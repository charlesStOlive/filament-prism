<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Les calculs de « Consommation IA » (`AiUsageStats`), partagés par ses
 * widgets : les demandes d'une période et d'un utilisateur, puis leurs
 * chiffres par ressource et par utilisateur. Chacun ne compte que les
 * siennes, sauf qui peut tout voir (voir `AiAccess`).
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
        return AiAccess::scope(AiInteraction::query())
            ->when(self::since($filters), fn (Builder $query, Carbon $since) => $query->where('created_at', '>=', $since))
            ->when($filters['user'] ?? null, fn (Builder $query, mixed $user) => $query->where('user_id', $user));
    }

    /** @param  array<string, mixed>|null  $filters */
    public static function since(?array $filters): ?Carbon
    {
        $period = (string) ($filters['period'] ?? '30');

        return $period === 'all' ? null : now()->subDays((int) $period)->startOfDay();
    }

    /**
     * Une ligne par ressource : demandes, échecs, résultats acceptés/ignorés, tokens, coût, durée
     * moyenne. Le coût n'est connu que pour les modèles de la table de prix (`priced` le compte) :
     * une somme partielle est signalée comme telle.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public static function byResource(?array $filters): Collection
    {
        $rows = self::query($filters)
            ->toBase()
            ->selectRaw('task')
            ->selectRaw('count(*) as requests')
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when status = 'applied' then 1 else 0 end) as applied")
            ->selectRaw("sum(case when status = 'discarded' then 1 else 0 end) as discarded")
            ->selectRaw('sum(prompt_tokens) as prompt_tokens')
            ->selectRaw('sum(completion_tokens) as completion_tokens')
            ->selectRaw('sum(cost) as cost')
            ->selectRaw('count(cost) as priced')
            ->groupBy('task')
            ->get()
            ->keyBy('task');

        $durations = self::query($filters)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->get(['task', 'started_at', 'finished_at'])
            ->groupBy('task')
            ->map(fn (Collection $interactions): float => $interactions->avg(fn (AiInteraction $interaction): int => $interaction->durationInSeconds() ?? 0));

        $labels = app(AiTaskRegistry::class)->all()->map(fn (AiTask $task): string => method_exists($task, 'label') ? $task->label() : $task->key());

        return $rows->map(function (object $row) use ($durations, $labels): array {
            $reviewed = (int) $row->applied + (int) $row->discarded;

            return [
                'task' => $row->task,
                'label' => $labels[$row->task] ?? $row->task,
                'requests' => (int) $row->requests,
                'failed' => (int) $row->failed,
                'failure_rate' => $row->requests > 0 ? (int) $row->failed / (int) $row->requests : null,
                'applied' => (int) $row->applied,
                'acceptance_rate' => $reviewed > 0 ? (int) $row->applied / $reviewed : null,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'cost' => $row->priced > 0 ? (float) $row->cost : null,
                'cost_is_partial' => $row->priced > 0 && (int) $row->priced < (int) $row->requests - (int) $row->failed,
                'average_duration' => $durations[$row->task] ?? null,
            ];
        })->sortByDesc('requests');
    }

    /**
     * Une ligne par utilisateur : demandes, échecs, tokens envoyés/reçus, coût.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public static function byUser(?array $filters): Collection
    {
        $rows = self::query($filters)
            ->toBase()
            ->selectRaw('user_id')
            ->selectRaw('count(*) as requests')
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw('sum(prompt_tokens) as prompt_tokens')
            ->selectRaw('sum(completion_tokens) as completion_tokens')
            ->selectRaw('sum(cost) as cost')
            ->selectRaw('count(cost) as priced')
            ->groupBy('user_id')
            ->get();

        $userModel = (string) config('auth.providers.users.model');
        $names = $userModel::query()->whereKey($rows->pluck('user_id')->filter()->all())->pluck('name', (new $userModel)->getKeyName());

        return $rows
            ->mapWithKeys(fn (object $row): array => [(string) ($row->user_id ?? 'none') => [
                'user' => $row->user_id === null ? 'Sans utilisateur (tâche automatique)' : ($names[$row->user_id] ?? '#'.$row->user_id),
                'requests' => (int) $row->requests,
                'failed' => (int) $row->failed,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) $row->prompt_tokens + (int) $row->completion_tokens,
                'cost' => $row->priced > 0 ? (float) $row->cost : null,
                'cost_is_partial' => $row->priced > 0 && (int) $row->priced < (int) $row->requests - (int) $row->failed,
            ]])
            ->sortByDesc('total_tokens');
    }
}
