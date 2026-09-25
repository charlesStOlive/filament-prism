<?php

namespace CharlesStOlive\FilamentPrism\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * La facture Anthropic, jour par jour : le cost report de l'Admin API
 * (`GET /v1/organizations/cost_report`), par ligne (`description`, ex.
 * « Claude Sonnet 5 Usage - Input Tokens ») et par workspace. Les montants y
 * sont des chaînes décimales **en cents** de dollar.
 *
 * Elle demande une **clé admin** (`ANTHROPIC_ADMIN_KEY`, `sk-ant-admin…`) ;
 * `workspace_id` restreint au workspace de l'application.
 */
class AnthropicBilling implements BillingSource
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['admin_key'] ?? null);
    }

    public function costs(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $page = null;

        do {
            $query = http_build_query(array_filter([
                'starting_at' => $from->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'ending_at' => $to->addDay()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                'limit' => 31,
                'page' => $page,
            ], fn (mixed $value): bool => $value !== null));
            $query .= '&group_by[]=workspace_id&group_by[]=description';

            $response = Http::withHeaders([
                'x-api-key' => (string) $this->config['admin_key'],
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(30)
                ->get('https://api.anthropic.com/v1/organizations/cost_report?'.$query)
                ->throw()
                ->json();

            $workspace = (string) ($this->config['workspace_id'] ?? '');

            foreach ($response['data'] ?? [] as $bucket) {
                $date = CarbonImmutable::parse((string) $bucket['starting_at'])->toDateString();

                foreach ($bucket['results'] ?? [] as $result) {
                    if ($workspace !== '' && ($result['workspace_id'] ?? null) !== $workspace) {
                        continue;
                    }

                    yield [
                        'date' => $date,
                        'project_id' => (string) ($result['workspace_id'] ?? ''),
                        'line_item' => (string) ($result['description'] ?? $result['cost_type'] ?? ''),
                        'model' => $result['model'] ?? null,
                        'amount' => ((float) ($result['amount'] ?? 0)) / 100,
                        'currency' => strtoupper((string) ($result['currency'] ?? 'USD')),
                    ];
                }
            }

            $page = ($response['has_more'] ?? false) ? ($response['next_page'] ?? null) : null;
        } while ($page !== null);
    }
}
