<?php

namespace CharlesStOlive\FilamentPrism\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * La facture OpenAI, jour par jour : l'API Costs (`GET /v1/organization/costs`),
 * par ligne de facture (`line_item`, ex. « gpt-image-1, input ») et par projet.
 *
 * Elle demande une **clé admin d'organisation** (`OPENAI_ADMIN_KEY`), pas la
 * clé de projet qui sert aux appels. `project_id` (`OPENAI_PROJECT_ID`)
 * restreint la facture au projet de l'application — sans lui, c'est celle de
 * toute l'organisation, autres applications comprises.
 */
class OpenAiBilling implements BillingSource
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
            // Des paramètres répétés (group_by=a&group_by=b), comme les attend l'API — pas group_by[0]=a.
            $query = http_build_query(array_filter([
                'start_time' => $from->startOfDay()->getTimestamp(),
                'end_time' => $to->endOfDay()->getTimestamp(),
                'bucket_width' => '1d',
                'limit' => 31,
                'page' => $page,
            ], fn (mixed $value): bool => $value !== null));
            $query .= '&group_by=line_item&group_by=project_id';

            if (filled($this->config['project_id'] ?? null)) {
                $query .= '&project_ids='.urlencode((string) $this->config['project_id']);
            }

            $response = Http::withToken((string) $this->config['admin_key'])
                ->timeout(30)
                ->get(rtrim((string) ($this->config['url'] ?? 'https://api.openai.com/v1'), '/').'/organization/costs?'.$query)
                ->throw()
                ->json();

            foreach ($response['data'] ?? [] as $bucket) {
                $date = CarbonImmutable::createFromTimestampUTC((int) $bucket['start_time'])->toDateString();

                foreach ($bucket['results'] ?? [] as $result) {
                    $lineItem = (string) ($result['line_item'] ?? '');

                    yield [
                        'date' => $date,
                        'project_id' => (string) ($result['project_id'] ?? ''),
                        'line_item' => $lineItem,
                        // « gpt-image-1, input » -> gpt-image-1
                        'model' => $lineItem === '' ? null : trim(explode(',', $lineItem)[0]),
                        'amount' => (float) ($result['amount']['value'] ?? 0),
                        'currency' => strtoupper((string) ($result['amount']['currency'] ?? 'usd')),
                    ];
                }
            }

            $page = ($response['has_more'] ?? false) ? ($response['next_page'] ?? null) : null;
        } while ($page !== null);
    }
}
