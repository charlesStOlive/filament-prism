<?php

namespace CharlesStOlive\FilamentPrism\Models;

use CharlesStOlive\FilamentPrism\States\Accepted;
use CharlesStOlive\FilamentPrism\States\AiInteractionStatus;
use CharlesStOlive\FilamentPrism\States\Archived;
use CharlesStOlive\FilamentPrism\States\Discarded;
use CharlesStOlive\FilamentPrism\States\Failed;
use CharlesStOlive\FilamentPrism\States\Pending;
use CharlesStOlive\FilamentPrism\States\Queued;
use CharlesStOlive\FilamentPrism\States\Refined;
use CharlesStOlive\FilamentPrism\States\Running;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Spatie\ModelStates\HasStates;

/**
 * Un appel IA, persisté dès sa réponse — jamais tenu seulement en état
 * Livewire, pour survivre à une coupure de session sans reperdre le travail
 * ni le budget de tokens déjà dépensé. Seuls son état (`status`, voir
 * `AiInteractionStatus`) et ce qui l'accompagne changent après coup ; le reste
 * est immuable.
 *
 * `thread_id`/`parent_interaction_id` ne servent à rien tant qu'aucun
 * dialogue multi-tours n'existe (chaque interaction v1 est un fil à elle
 * seule) : colonnes posées maintenant pour éviter un ALTER plus tard sur une
 * table qui contiendra déjà de l'historique réel.
 *
 * `meta` (json, nullable) porte ce dont un renderer a besoin sans connaître le
 * `CorrectionSubject` d'origine (qui ne survit pas à la requête ayant appelé
 * l'IA) : `meta.labels` (le libellé de chaque champ, voir
 * `CorrectableField::labelsByName()`) pour `TextDiffRenderer`/
 * `GroupedTextDiffRenderer` ; un futur `ChoiceRenderer` y noterait l'option
 * choisie.
 *
 * `subject_key` distingue plusieurs `CorrectionSubject` qui se rattacheraient
 * au même modèle (ex. plusieurs périodes d'un même voyage, voir
 * `FieldsCorrectionSubject`) — `null` quand le modèle seul identifie déjà le
 * sujet (le cas `Correctable` le plus courant).
 *
 * Toute demande naît brouillon (`draft`, `AiRunner::draft()`), puis part à
 * l'IA (`AiRunner::submit()`) : `queued`/`running` -> `pending` (le résultat
 * attend qu'on le vérifie) ou `failed` ; vérifiée, elle est `accepted`,
 * `discarded` ou `refined` ; terminée, elle peut être `archived` (voir
 * `AiInteractionStatus`). `meta.feedback` : ce qu'on a demandé de corriger en
 * l'affinant, sur la version qui en résulte. `started_at`/`finished_at` en
 * donnent la durée, `cost` ce qu'elle a coûté (voir `AiCost`), `error` le
 * message sûr à montrer quand elle a échoué. `applied_at` : le résultat accepté
 * a aussi été appliqué quelque part (seulement pour les ressources qui le font).
 *
 * Un fil (`thread_id`) regroupe une demande et ses variantes : « Refaire avec
 * d'autres réglages » crée une demande du même fil, dont le parent
 * (`parent_interaction_id`) est celle qu'on affine (`AiRunner::refine()`).
 */
class AiInteraction extends Model
{
    use HasStates;

    /** Les valeurs de `status` en base (les noms des états), pour les requêtes. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_REFINED = 'refined';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ARCHIVED = 'archived';

    /** Les statuts d'une demande qui n'a pas encore de résultat : l'affichage se rafraîchit tant qu'il en reste. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    protected $fillable = [
        'user_id',
        'task',
        'provider',
        'model',
        'correctable_type',
        'correctable_id',
        'subject_key',
        'trackable_type',
        'trackable_id',
        'input',
        'output',
        'meta',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost',
        'currency',
        'cost_eur',
        'kind',
        'status',
        'started_at',
        'finished_at',
        'error',
        'applied_at',
        'archived_at',
        'archived_from',
        'thread_id',
        'parent_interaction_id',
    ];

    protected $casts = [
        'status' => AiInteractionStatus::class,
        'input' => 'array',
        'output' => 'array',
        'meta' => 'array',
        'applied_at' => 'datetime',
        'archived_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cost' => 'decimal:8',
        'cost_eur' => 'decimal:8',
    ];

    public function correctable(): MorphTo
    {
        return $this->morphTo();
    }

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> statut => libellé */
    public static function statusLabels(): array
    {
        return AiInteractionStatus::getStatesLabel(static::class);
    }

    /** La couleur Filament d'un statut, pour un badge. */
    public static function statusColor(?string $status): string
    {
        return AiInteractionStatus::getStatesColor(static::class)[$status] ?? 'gray';
    }

    public function statusLabel(): string
    {
        return $this->status?->getLabel() ?? '';
    }

    /** L'état est-il l'un de ceux-là ? (`AiInteraction::STATUS_*`) */
    public function isStatus(string ...$statuses): bool
    {
        return in_array($this->status?->getValue(), $statuses, true);
    }

    /**
     * Les images produites par une ressource d'image (`output.images`), avec leur adresse.
     *
     * @return array<int, array{disk: string, path: string, url: string, mime_type?: string}>
     */
    public function images(): array
    {
        return collect($this->output['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_array($image) && isset($image['disk'], $image['path']))
            ->map(fn (array $image): array => [...$image, 'url' => Storage::disk($image['disk'])->url($image['path'])])
            ->values()
            ->all();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_interaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model'), 'user_id');
    }

    /** Les demandes de ce fil : celle-ci, celles qu'elle refait et ses variantes, de la plus ancienne à la plus récente. */
    public function thread(): Builder
    {
        return static::query()->where('thread_id', $this->thread_id)->oldest('id');
    }

    /** @param  Builder<static>  $query */
    public function scopeTrackedBy(Builder $query, Model $trackable): void
    {
        $query->where('trackable_type', $trackable->getMorphClass())->where('trackable_id', $trackable->getKey());
    }

    /** @param  Builder<static>  $query  Sans les demandes archivées. */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), '!=', self::STATUS_ARCHIVED);
    }

    public function isActive(): bool
    {
        return $this->status?->isActive() ?? false;
    }

    public function hasResult(): bool
    {
        return $this->status?->hasResult() ?? false;
    }

    public function isDraft(): bool
    {
        return $this->isStatus(self::STATUS_DRAFT);
    }

    /** Ce qu'on a demandé de corriger en affinant la version précédente (voir `AiRunner::refine()`). */
    public function feedback(): ?string
    {
        $feedback = trim((string) ($this->meta['feedback'] ?? ''));

        return $feedback === '' ? null : $feedback;
    }

    public function isArchived(): bool
    {
        return $this->isStatus(self::STATUS_ARCHIVED);
    }

    /** Terminée (acceptée, ignorée ou en échec) : elle peut être archivée. */
    public function canBeArchived(): bool
    {
        return $this->status?->canTransitionTo(Archived::class) ?? false;
    }

    /** Le temps de l'appel, ou, tant qu'il tourne, le temps écoulé depuis son début. */
    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), absolute: true);
    }

    public function markQueued(): void
    {
        $this->moveTo(Queued::class);
    }

    public function markRunning(): void
    {
        $this->moveTo(Running::class, ['started_at' => now()]);
    }

    /** @param  array<string, mixed>  $result  Ce que la réponse a produit (sortie, tokens, coût...). */
    public function markPending(array $result = []): void
    {
        $this->moveTo(Pending::class, [...$result, 'error' => null, 'finished_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->moveTo(Failed::class, ['error' => $error, 'finished_at' => now()]);
    }

    /** Le résultat convient. */
    public function markAccepted(): void
    {
        $this->moveTo(Accepted::class);
    }

    /** Le résultat convient, et il a été appliqué quelque part (voir `AiResource::accept()`). */
    public function markApplied(): void
    {
        $this->moveTo(Accepted::class, ['applied_at' => now()]);
    }

    public function markDiscarded(): void
    {
        $this->moveTo(Discarded::class);
    }

    /** Une nouvelle version du même fil la remplace (voir `AiRunner::refine()`). */
    public function markRefined(): void
    {
        $this->moveTo(Refined::class);
    }

    public function archive(): void
    {
        $this->status->transitionTo(Archived::class);
    }

    /** Revient dans l'issue qu'elle avait avant d'être archivée. */
    public function unarchive(): void
    {
        if ($this->isArchived()) {
            $this->status->transitionTo($this->archived_from);
        }
    }

    /**
     * Passe dans un état en écrivant `$attributes` avec lui. Un passage que
     * `AiInteractionStatus` n'autorise pas lève `CouldNotPerformTransition`,
     * sauf vers l'état où la demande est déjà : seuls ses champs changent.
     *
     * @param  class-string<AiInteractionStatus>  $state
     * @param  array<string, mixed>  $attributes
     */
    private function moveTo(string $state, array $attributes = []): void
    {
        $this->forceFill($attributes);

        if ($this->status?->equals($state)) {
            $this->save();

            return;
        }

        // La cible suit en argument : une transition qui mène à plusieurs états (`Submit`) la lit là.
        $this->status->transitionTo($state, $state);
    }
}
