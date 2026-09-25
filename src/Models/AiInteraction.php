<?php

namespace CharlesStOlive\FilamentPrism\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un appel IA, persisté dès sa réponse — jamais tenu seulement en état
 * Livewire, pour survivre à une coupure de session sans reperdre le travail
 * ni le budget de tokens déjà dépensé. `status` est le seul champ qui change
 * après coup (`pending` -> `applied`/`discarded`) ; le reste est immuable.
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
 * Une demande mise en file (`AiRunner::queue()`) existe dès qu'on la fait :
 * `queued` -> `running` -> `pending` (le résultat attend qu'on le vérifie) ou
 * `failed`. `started_at`/`finished_at` en donnent la durée, `cost` ce qu'elle
 * a coûté (voir `AiCost`), `error` le message sûr à montrer quand elle a échoué.
 *
 * Un fil (`thread_id`) regroupe une demande et ses variantes : « Refaire avec
 * d'autres réglages » crée une demande du même fil, dont le parent
 * (`parent_interaction_id`) est celle qu'on refait (`AiRunner::rerun()`).
 */
class AiInteraction extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_FAILED = 'failed';

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
        'thread_id',
        'parent_interaction_id',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'meta' => 'array',
        'applied_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cost' => 'decimal:6',
        'cost_eur' => 'decimal:6',
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
        return [
            self::STATUS_QUEUED => 'En file',
            self::STATUS_RUNNING => 'En cours',
            self::STATUS_PENDING => 'À vérifier',
            self::STATUS_APPLIED => 'Acceptée',
            self::STATUS_DISCARDED => 'Ignorée',
            self::STATUS_FAILED => 'Échec',
        ];
    }

    /** La couleur Filament d'un statut, pour un badge. */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_RUNNING => 'info',
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPLIED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
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

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function hasResult(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPLIED, self::STATUS_DISCARDED], true);
    }

    /** Le temps de l'appel, ou, tant qu'il tourne, le temps écoulé depuis son début. */
    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), absolute: true);
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => self::STATUS_RUNNING, 'started_at' => now()])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'error' => $error, 'finished_at' => now()])->save();
    }

    public function markApplied(): void
    {
        $this->forceFill(['status' => self::STATUS_APPLIED, 'applied_at' => now()])->save();
    }

    public function markDiscarded(): void
    {
        $this->forceFill(['status' => self::STATUS_DISCARDED])->save();
    }
}
