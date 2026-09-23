<?php

namespace CharlesStOlive\FilamentPrism\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
 */
class AiInteraction extends Model
{
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
        'status',
        'applied_at',
        'thread_id',
        'parent_interaction_id',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'meta' => 'array',
        'applied_at' => 'datetime',
    ];

    public function correctable(): MorphTo
    {
        return $this->morphTo();
    }

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_interaction_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markApplied(): void
    {
        $this->forceFill(['status' => 'applied', 'applied_at' => now()])->save();
    }

    public function markDiscarded(): void
    {
        $this->forceFill(['status' => 'discarded'])->save();
    }
}
