<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiBilledCost;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Les calculs de « Consommation IA » (`AiUsageStats`), partagés par ses
 * widgets : les demandes d'une période et d'un utilisateur, puis leurs
 * chiffres par ressource, par utilisateur, par modèle, et ce que les
 * fournisseurs ont réellement facturé. Chacun ne compte que les siennes, sauf
 * qui peut tout voir (voir `AiAccess`).
 *
 * Deux coûts, en euros :
 * - **estimé** : tokens × prix du catalogue, figé à chaque appel (`cost_eur`) ;
 * - **facturé** : ce que le fournisseur a relevé dans sa facture
 *   (`ai_billed_costs`, voir `Billing\AiBillingSync`). La facture ne connaît
 *   ni les ressources ni les utilisateurs : la part de chacun est son estimation
 *   **recalée** sur la facture — multipliée, fournisseur par fournisseur, par
 *   le rapport facturé / estimé de toute la période.
 */
final class AiUsage
{
    /** @var array<string, string> nombre de jours => libellé ; `all` : depuis toujours */
    public const PERIODS = [
        '7' => '7 derniers jours',
        '30' => '30 derniers jours',
        '90' => '3 derniers mois',
        '365' => '12 derniers mois',
        'all' => 'Depuis le début',
    ];

    /** @param  array<string, mixed>|null  $filters  Les filtres de la page : `period`, `user`. */
    public static function query(?array $filters): Builder
    {
        return AiAccess::scope(self::unscopedQuery($filters))
            ->when($filters['user'] ?? null, fn (Builder $query, mixed $user) => $query->where('user_id', $user));
    }

    /** @param  array<string, mixed>|null  $filters */
    public static function since(?array $filters): ?Carbon
    {
        $period = (string) ($filters['period'] ?? '30');

        return $period === 'all' ? null : now()->subDays((int) $period)->startOfDay();
    }

    /**
     * Une ligne par ressource : demandes, échecs, résultats acceptés/ignorés, tokens, coût estimé et
     * recalé sur la facture, durée moyenne. Le coût n'est connu que pour les modèles qui ont un prix
     * (`priced` le compte) : une somme partielle est signalée comme telle.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public static function byResource(?array $filters): Collection
    {
        $rows = self::totals(self::query($filters), 'task');
        $billed = self::billedShares(self::query($filters), 'task', $filters);

        $durations = self::query($filters)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->get(['task', 'started_at', 'finished_at'])
            ->groupBy('task')
            ->map(fn (Collection $interactions): float => $interactions->avg(fn (AiInteraction $interaction): int => $interaction->durationInSeconds() ?? 0));

        $labels = app(AiTaskRegistry::class)->all()->map(fn (AiTask $task): string => method_exists($task, 'label') ? $task->label() : $task->key());

        return $rows->map(function (array $row, string $task) use ($durations, $labels, $billed): array {
            $reviewed = $row['applied'] + $row['discarded'];

            return [
                ...$row,
                'task' => $task,
                'label' => $labels[$task] ?? $task,
                'failure_rate' => $row['requests'] > 0 ? $row['failed'] / $row['requests'] : null,
                'acceptance_rate' => $reviewed > 0 ? $row['applied'] / $reviewed : null,
                'billed_eur' => $billed[$task] ?? null,
                'average_duration' => $durations[$task] ?? null,
            ];
        })->sortByDesc('requests');
    }

    /**
     * Une ligne par utilisateur : demandes, échecs, tokens envoyés/reçus, coût estimé et recalé.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public static function byUser(?array $filters): Collection
    {
        $rows = self::totals(self::query($filters), 'user_id');
        $billed = self::billedShares(self::query($filters), 'user_id', $filters);

        $userModel = (string) config('auth.providers.users.model');
        $names = $userModel::query()->whereKey($rows->keys()->filter(fn (string $key): bool => $key !== '')->all())->pluck('name', (new $userModel)->getKeyName());

        return $rows
            ->mapWithKeys(fn (array $row, string $userId): array => [($userId === '' ? 'none' : $userId) => [
                ...$row,
                'user' => $userId === '' ? 'Sans utilisateur (tâche automatique)' : ($names[$userId] ?? '#'.$userId),
                'billed_eur' => $billed[$userId] ?? null,
            ]])
            ->sortByDesc('total_tokens');
    }

    /**
     * Une ligne par modèle utilisé (fournisseur + modèle) : son type, demandes, tokens, coût estimé,
     * ce que sa ligne de facture a coûté, et s'il a un prix dans le catalogue.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public static function byModel(?array $filters): Collection
    {
        $rows = self::query($filters)
            ->toBase()
            ->selectRaw('provider, model, max(kind) as kind, count(*) as requests')
            ->selectRaw('sum(total_tokens) as tokens, sum(cost_eur) as cost_eur')
            ->groupBy('provider', 'model')
            ->get();

        $billed = AiAccess::canSeeAll()
            ? self::billedQuery($filters)->toBase()->selectRaw('provider, model, sum(amount_eur) as amount_eur')->groupBy('provider', 'model')->get()
                ->mapWithKeys(fn (object $row): array => [$row->provider.'|'.$row->model => (float) $row->amount_eur])
            : collect();

        return $rows->mapWithKeys(fn (object $row): array => [$row->provider.'|'.$row->model => [
            'provider' => AiProviders::label((string) $row->provider),
            'model' => $row->model,
            'kind' => AiProviders::modelType((string) $row->provider, (string) $row->model) ?? $row->kind,
            'requests' => (int) $row->requests,
            'tokens' => (int) $row->tokens,
            'cost_eur' => $row->cost_eur === null ? null : (float) $row->cost_eur,
            'billed_eur' => $billed[$row->provider.'|'.$row->model] ?? null,
            'priced' => AiProviders::model((string) $row->provider, (string) $row->model) !== null
                && is_numeric(AiProviders::model((string) $row->provider, (string) $row->model)['input'] ?? null),
        ]])->sortByDesc('requests');
    }

    /**
     * Par fournisseur, sur la période : ce qu'il a facturé (relevé de sa facture), ce que
     * l'application en a estimé (toutes ses demandes, tous utilisateurs), et le rapport des deux.
     * Seulement pour qui peut tout voir : une facture est celle de tout le monde.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public static function billing(?array $filters): Collection
    {
        $billed = self::billedQuery($filters)->toBase()
            ->selectRaw('provider, sum(amount_eur) as amount_eur, sum(amount) as amount, max(currency) as currency, max(date) as last_date')
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');

        $estimated = self::estimatedByProvider($filters);

        return collect(array_unique([...$billed->keys()->all(), ...$estimated->keys()->all()]))
            ->mapWithKeys(function (string $provider) use ($billed, $estimated): array {
                $bill = $billed->get($provider);
                $billedEur = $bill?->amount_eur === null ? null : (float) $bill->amount_eur;
                $estimatedEur = $estimated[$provider] ?? null;

                return [$provider => [
                    'provider' => AiProviders::label($provider),
                    'billed' => $bill === null ? null : (float) $bill->amount,
                    'currency' => $bill->currency ?? AiProviders::currency($provider),
                    'billed_eur' => $billedEur,
                    'estimated_eur' => $estimatedEur,
                    'ratio' => $billedEur !== null && $estimatedEur ? $billedEur / $estimatedEur : null,
                    'last_date' => $bill?->last_date,
                ]];
            })
            ->sortByDesc('billed_eur');
    }

    /**
     * Ce que la facture de la période couvre de plus que l'estimation, fournisseur par fournisseur :
     * facturé / estimé. Sans facture relevée (ou sans estimation), pas de facteur.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<string, float>
     */
    public static function calibration(?array $filters): array
    {
        $billed = self::billedQuery($filters)->toBase()->selectRaw('provider, sum(amount_eur) as amount_eur')->groupBy('provider')->pluck('amount_eur', 'provider');
        $estimated = self::estimatedByProvider($filters);

        return collect($billed)
            ->filter(fn (mixed $amount, string $provider): bool => $amount !== null && ($estimated[$provider] ?? 0) > 0)
            ->map(fn (mixed $amount, string $provider): float => (float) $amount / $estimated[$provider])
            ->all();
    }

    /** @return Collection<string, array<string, mixed>> Les colonnes communes aux tables de chiffres, par groupe. */
    private static function totals(Builder $query, string $groupBy): Collection
    {
        return $query->toBase()
            ->selectRaw("{$groupBy} as group_key")
            ->selectRaw('count(*) as requests')
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when status = 'applied' then 1 else 0 end) as applied")
            ->selectRaw("sum(case when status = 'discarded' then 1 else 0 end) as discarded")
            ->selectRaw('sum(prompt_tokens) as prompt_tokens')
            ->selectRaw('sum(completion_tokens) as completion_tokens')
            ->selectRaw('sum(cost_eur) as cost_eur')
            ->selectRaw('count(cost_eur) as priced')
            ->groupBy($groupBy)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->group_key => [
                'requests' => (int) $row->requests,
                'failed' => (int) $row->failed,
                'applied' => (int) $row->applied,
                'discarded' => (int) $row->discarded,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) $row->prompt_tokens + (int) $row->completion_tokens,
                'cost_eur' => $row->priced > 0 ? (float) $row->cost_eur : null,
                'cost_is_partial' => $row->priced > 0 && (int) $row->priced < (int) $row->requests - (int) $row->failed,
            ]]);
    }

    /**
     * La part de la facture de chaque groupe : son estimation, fournisseur par fournisseur, multipliée
     * par le facteur de recalage de ce fournisseur. `null` quand aucune facture ne couvre le groupe.
     *
     * @param  array<string, mixed>|null  $filters
     * @return array<string, float>
     */
    private static function billedShares(Builder $query, string $groupBy, ?array $filters): array
    {
        $factors = self::calibration($filters);

        if ($factors === []) {
            return [];
        }

        return $query->toBase()
            ->selectRaw("{$groupBy} as group_key, provider, sum(cost_eur) as cost_eur")
            ->whereNotNull('cost_eur')
            ->groupBy($groupBy, 'provider')
            ->get()
            ->filter(fn (object $row): bool => isset($factors[$row->provider]))
            ->groupBy(fn (object $row): string => (string) $row->group_key)
            ->map(fn (Collection $rows): float => $rows->sum(fn (object $row): float => (float) $row->cost_eur * $factors[$row->provider]))
            ->all();
    }

    /** @return Collection<string, float> L'estimation de toutes les demandes de la période, par fournisseur. */
    private static function estimatedByProvider(?array $filters): Collection
    {
        return self::unscopedQuery($filters)->toBase()
            ->selectRaw('provider, sum(cost_eur) as cost_eur')
            ->whereNotNull('cost_eur')
            ->groupBy('provider')
            ->pluck('cost_eur', 'provider')
            ->map(fn (mixed $amount): float => (float) $amount);
    }

    /** @param  array<string, mixed>|null  $filters */
    private static function unscopedQuery(?array $filters): Builder
    {
        return AiInteraction::query()
            ->when(self::since($filters), fn (Builder $query, Carbon $since) => $query->where('created_at', '>=', $since));
    }

    /** @param  array<string, mixed>|null  $filters */
    private static function billedQuery(?array $filters): Builder
    {
        return AiBilledCost::query()
            ->when(self::since($filters), fn (Builder $query, Carbon $since) => $query->whereDate('date', '>=', $since->toDateString()));
    }
}
