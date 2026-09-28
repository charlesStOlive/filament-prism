<?php

namespace CharlesStOlive\FilamentPrism\States;

use CharlesStOlive\FilamentPrism\States\Transitions\Submit;
use CharlesStOlive\FilamentPrism\States\Transitions\ToArchived;
use CharlesStOlive\FilamentPrism\States\Transitions\Unarchive;
use CharlesStOlive\FilamentStateFusionEnhanced\Concerns\StateFusionInfo;
use CharlesStOlive\FilamentStateFusionEnhanced\Contracts\HasFilamentState;
use CharlesStOlive\FilamentStateFusionEnhanced\Contracts\HasFilamentStateFusion;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Où en est une demande IA (`AiInteraction::$status`) :
 *
 *     draft -> queued | running            (Soumettre : en arrière-plan, ou tout de suite)
 *     queued -> running -> pending | failed
 *     pending -> accepted | discarded | refined
 *     accepted | discarded | refined | failed -> archived -> (l'issue d'avant)
 *
 * **Brouillon** : toute demande commence là. On y voit ce qui partira (les
 * textes à corriger, les photos et les réglages) et rien n'est payé tant
 * qu'on ne l'a pas soumise ; un brouillon jamais envoyé se supprime pour de bon.
 *
 * **Acceptée** est l'issue positive de toute demande — son résultat convient.
 * Certaines ressources l'appliquent en plus quelque part (une image ajoutée à
 * une bibliothèque, une correction écrite dans un texte) : `applied_at` le dit.
 *
 * **Affinée** : on a demandé mieux (« Affiner », avec ce qui ne va pas) ; une
 * nouvelle version du même fil la remplace (voir `AiRunner::refine()`). Ce
 * n'est ni un refus ni une acceptation.
 *
 * **Archivée** range une demande terminée : elle quitte les demandes d'un
 * modèle (un voyage) et ne reste que dans les listes globales. L'issue d'avant
 * est gardée (`archived_from`), pour les statistiques et pour désarchiver.
 *
 * Les noms des états sont les valeurs de la colonne : une requête peut toujours
 * filtrer sur `status = 'pending'` (voir les constantes `AiInteraction::STATUS_*`).
 */
abstract class AiInteractionStatus extends State implements HasFilamentState, HasFilamentStateFusion
{
    use StateFusionInfo;

    abstract public function getLabel(): string;

    abstract public function getColor(): string;

    abstract public function getIcon(): string;

    public function getDescription(): ?string
    {
        return null;
    }

    /** Pas encore de résultat : l'affichage se rafraîchit tant qu'elle en est là. */
    public function isActive(): bool
    {
        return false;
    }

    /** Un résultat à montrer (à vérifier, accepté ou ignoré). */
    public function hasResult(): bool
    {
        return false;
    }

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            ->registerState([Draft::class, Queued::class, Running::class, Pending::class, Accepted::class, Discarded::class, Refined::class, Failed::class, Archived::class])
            ->allowTransition(Draft::class, Queued::class, Submit::class)
            ->allowTransition(Draft::class, Running::class, Submit::class)
            ->allowTransition(Queued::class, Running::class)
            ->allowTransition(Running::class, Pending::class)
            ->allowTransition([Queued::class, Running::class], Failed::class)
            ->allowTransition(Pending::class, Accepted::class)
            ->allowTransition(Pending::class, Discarded::class)
            ->allowTransition(Pending::class, Refined::class)
            ->allowTransition([Accepted::class, Discarded::class, Refined::class, Failed::class], Archived::class, ToArchived::class)
            ->allowTransition(Archived::class, Accepted::class, Unarchive::class)
            ->allowTransition(Archived::class, Discarded::class, Unarchive::class)
            ->allowTransition(Archived::class, Refined::class, Unarchive::class)
            ->allowTransition(Archived::class, Failed::class, Unarchive::class);
    }
}
