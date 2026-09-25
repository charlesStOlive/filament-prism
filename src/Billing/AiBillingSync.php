<?php

namespace CharlesStOlive\FilamentPrism\Billing;

use Carbon\CarbonImmutable;
use CharlesStOlive\FilamentPrism\Models\AiBilledCost;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Support\AiCost;
use CharlesStOlive\FilamentPrism\Support\AiProviders;
use CharlesStOlive\FilamentPrism\Support\ExchangeRates;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relève ce que les fournisseurs ont réellement facturé (`BillingSource`), et
 * le convertit en euros au taux BCE du jour (`ExchangeRates`) — puis complète
 * les demandes qui n'avaient pas encore de coût estimé ou d'équivalent en euros.
 *
 * Idempotent : relancer sur les mêmes jours remplace les montants (un jour en
 * cours se complète au fil des relevés). Lancée par
 * `php artisan filament-prism:sync-billing`, chaque jour par le scheduler de
 * l'application, ou à la main depuis « Consommation IA ».
 */
class AiBillingSync
{
    public function __construct(private readonly ExchangeRates $rates) {}

    /**
     * @return array{rates: int|string, providers: array<string, int|string>, interactions: int}
     *                                                                                           Par fournisseur : le nombre de lignes relevées, ou le message d'erreur.
     */
    public function sync(int $days = 7): array
    {
        try {
            $rates = $this->rates->sync();
        } catch (Throwable $exception) {
            report($exception);
            $rates = 'Taux BCE indisponibles : '.$exception->getMessage();
        }

        $to = CarbonImmutable::now('UTC');
        $from = $to->subDays(max(1, $days) - 1);
        $providers = [];

        foreach (AiProviders::all() as $provider => $config) {
            $source = $this->source($provider);

            if ($source === null || ! $source->isConfigured()) {
                continue;
            }

            try {
                $providers[$provider] = $this->store($provider, $source->costs($from, $to));
            } catch (Throwable $exception) {
                Log::warning("filament-prism : facture {$provider} illisible", ['exception' => $exception]);
                $providers[$provider] = 'Erreur : '.$exception->getMessage();
            }
        }

        return ['rates' => $rates, 'providers' => $providers, 'interactions' => $this->backfillInteractions()];
    }

    public function source(string $provider): ?BillingSource
    {
        $config = AiProviders::billing($provider);
        $class = $config['source'] ?? null;

        return is_string($class) && is_subclass_of($class, BillingSource::class) ? new $class($config) : null;
    }

    /** Les fournisseurs dont la facture peut être relevée (une source et sa clé). @return array<int, string> */
    public function configuredProviders(): array
    {
        return array_values(array_filter(array_keys(AiProviders::all()), fn (string $provider): bool => $this->source($provider)?->isConfigured() ?? false));
    }

    /** @param  iterable<array<string, mixed>>  $lines */
    private function store(string $provider, iterable $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            AiBilledCost::query()->updateOrCreate(
                ['provider' => $provider, 'date' => $line['date'], 'project_id' => $line['project_id'], 'line_item' => $line['line_item']],
                [
                    'model' => $line['model'],
                    'amount' => $line['amount'],
                    'currency' => $line['currency'],
                    'amount_eur' => $this->rates->toEur((float) $line['amount'], $line['currency'], CarbonImmutable::parse($line['date'])),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Les demandes à compléter : sans coût estimé alors que leur modèle a désormais un prix (appel fait
     * avant que le catalogue le connaisse — calculé d'après leurs tokens), ou sans équivalent en euros
     * (appel fait avant le premier relevé des taux).
     */
    private function backfillInteractions(): int
    {
        $count = 0;

        AiInteraction::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('cost')->where('total_tokens', '>', 0))
                ->orWhere(fn ($query) => $query->whereNotNull('cost')->whereNull('cost_eur')))
            ->chunkById(200, function ($interactions) use (&$count): void {
                foreach ($interactions as $interaction) {
                    $cost = $interaction->cost ?? AiCost::of(
                        $interaction->provider,
                        $interaction->model,
                        (int) $interaction->prompt_tokens,
                        (int) $interaction->completion_tokens,
                        (array) ($interaction->meta['usage']['input_tokens_details'] ?? []),
                    );

                    if ($cost === null) {
                        continue;
                    }

                    $currency = $interaction->currency ?? AiProviders::currency($interaction->provider);

                    $interaction->forceFill([
                        'cost' => $cost,
                        'currency' => $currency,
                        'cost_eur' => $this->rates->toEur((float) $cost, $currency, $interaction->created_at ?? now()),
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }
}
