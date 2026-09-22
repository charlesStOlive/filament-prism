<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Ce que `CorrectionService::correct()` a besoin de savoir pour appeler l'IA,
 * quelle que soit la nature du texte corrigé. Deux implémentations :
 *
 * - `Correctable` (trait) : le texte vit dans les colonnes d'un modèle
 *   Eloquent — c'est lui-même le sujet, `model()` renvoie `$this`.
 * - `FieldsCorrectionSubject` : le texte vit ailleurs (un tableau d'état
 *   Livewire, par exemple) ; `model()` renvoie un modèle stable auquel
 *   rattacher l'`AiInteraction` pour qu'elle survive, même si ce n'est pas
 *   lui qui porte les champs corrigés.
 *
 * `CorrectionSubject` ne sait dire que ce qu'il faut envoyer à l'IA. Écrire
 * le résultat corrigé est une préoccupation distincte, différente selon le
 * cas (voir `CorrectionService::apply()` et `CorrectionReview`).
 */
interface CorrectionSubject
{
    /** Le modèle auquel l'AiInteraction se rattache, pour durer. */
    public function model(): Model;

    /**
     * Distingue plusieurs sujets qui se rattacheraient au même modèle (ex.
     * plusieurs périodes d'un même voyage) : sans ça, la réutilisation d'une
     * interaction `pending` (voir CorrectionService::correct()) confondrait
     * leurs corrections respectives. `null` quand le modèle seul suffit à
     * identifier le sujet — le cas `Correctable` le plus courant.
     */
    public function key(): ?string;

    /** @return array<int, CorrectableField> */
    public function fields(): array;

    /** @return array<string, mixed> */
    public function extractValues(): array;
}
