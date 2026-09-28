<?php

namespace CharlesStOlive\FilamentPrism\States\Transitions;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Spatie\ModelStates\Transition;

/**
 * Ressort une demande des archives, dans l'issue qu'elle avait (`archived_from`) —
 * `AiInteraction::unarchive()` demande toujours cette issue-là comme cible.
 */
class Unarchive extends Transition implements HasColor, HasDescription, HasIcon, HasLabel
{
    public function __construct(private AiInteraction $interaction) {}

    public function handle(): AiInteraction
    {
        $this->interaction->forceFill([
            'status' => $this->interaction->archived_from,
            'archived_from' => null,
            'archived_at' => null,
        ])->save();

        return $this->interaction;
    }

    public function getLabel(): string
    {
        return 'Désarchiver';
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-archive-box-x-mark';
    }

    public function getDescription(): string
    {
        return 'La demande revient dans la liste de son modèle.';
    }
}
