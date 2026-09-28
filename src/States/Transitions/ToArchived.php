<?php

namespace CharlesStOlive\FilamentPrism\States\Transitions;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\States\Archived;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Spatie\ModelStates\Transition;

/** Range une demande terminée, en gardant son issue (`archived_from`) : voir `AiInteractionStatus`. */
class ToArchived extends Transition implements HasColor, HasDescription, HasIcon, HasLabel
{
    public function __construct(private AiInteraction $interaction) {}

    public function handle(): AiInteraction
    {
        $this->interaction->forceFill([
            'archived_from' => $this->interaction->status->getValue(),
            'archived_at' => now(),
            'status' => Archived::class,
        ])->save();

        return $this->interaction;
    }

    public function getLabel(): string
    {
        return 'Archiver';
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-archive-box-arrow-down';
    }

    public function getDescription(): string
    {
        return 'La demande quitte la liste de son modèle ; elle reste dans « Demandes IA ».';
    }
}
