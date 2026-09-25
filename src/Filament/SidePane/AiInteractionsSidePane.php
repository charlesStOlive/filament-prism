<?php

namespace CharlesStOlive\FilamentPrism\Filament\SidePane;

use CharlesStOlive\FilamentPrism\Livewire\AiInteractionList;
use CharlesStOlive\FilamentUi\Split\SidePane;
use Illuminate\Database\Eloquent\Model;

/**
 * Les demandes IA faites pour un modèle (ex. un voyage), dans le volet latéral
 * de filament-ui (`HasSidePane`) : on les suit et on les vérifie sans quitter
 * la page d'où elles sont parties (voir `AiInteractionList::forTrackable()`).
 *
 *     AiInteractionsSidePane::NAME => AiInteractionsSidePane::forTrackable($this->record)
 *
 * Dépend de charlesstolive/filament-ui (déclaré en `suggest`, comme
 * `CorrectionSidePane`).
 */
class AiInteractionsSidePane
{
    public const NAME = 'ai-interactions';

    /** @param  array<int, string>  $tasks  Les ressources montrées ; vide : celles mises en file. */
    public static function forTrackable(Model $trackable, array $tasks = []): SidePane
    {
        return SidePane::make(self::NAME)
            ->label('Demandes IA')
            ->icon('heroicon-o-sparkles')
            ->schema([AiInteractionList::forTrackable($trackable, $tasks)]);
    }
}
