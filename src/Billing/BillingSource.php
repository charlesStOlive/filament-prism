<?php

namespace CharlesStOlive\FilamentPrism\Billing;

use Carbon\CarbonImmutable;

/**
 * Ce qu'un fournisseur a réellement facturé, lu dans son API de facturation —
 * par jour et par ligne de facture. Déclarée par fournisseur dans
 * `filament-prism.providers.<provider>.billing.source` ; une application en
 * ajoute pour un autre fournisseur sans toucher au package.
 */
interface BillingSource
{
    /** @param  array<string, mixed>  $config  `filament-prism.providers.<provider>.billing` */
    public function __construct(array $config);

    /** La source a de quoi interroger l'API (le plus souvent une clé admin, distincte de la clé d'API). */
    public function isConfigured(): bool;

    /**
     * @return iterable<array{date: string, project_id: string, line_item: string, model: string|null, amount: float, currency: string}>
     */
    public function costs(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
