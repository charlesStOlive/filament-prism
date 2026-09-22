<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Illuminate\Contracts\View\View;

/**
 * Sait transformer la réponse d'une `AiInteraction` en affichage : un diff
 * texte aujourd'hui (`TextDiffRenderer`), demain par exemple un choix parmi
 * plusieurs options rendu en boutons. `CorrectionReview` ne connaît que ce
 * contrat, jamais le détail d'un renderer précis — la tâche (`AiTask`) dit
 * lequel utiliser.
 */
interface AiResultRenderer
{
    public function render(AiInteraction $interaction): View;
}
