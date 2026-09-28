<?php

namespace CharlesStOlive\FilamentPrism\Filament\SidePane;

use CharlesStOlive\FilamentPrism\Livewire\AiInteractionList;
use CharlesStOlive\FilamentUi\Split\SidePane;
use Illuminate\Database\Eloquent\Model;

/**
 * Les demandes IA (`AiInteractionList`) dans le volet latéral de filament-ui
 * (`HasSidePane`), posé à côté du formulaire : on les suit et on les vérifie
 * sans quitter la page. Le plus simple est de le laisser construire par
 * `AiInteractionsAction` (`->display(AiInteractionsDisplay::SidePane)`,
 * `->toSidePane()`), qui ouvre aussi la modale et le slide-over.
 *
 *     AiInteractionsSidePane::NAME => AiInteractionsSidePane::make($this->record, tasks: ['photo-sketch'])
 *
 * Dépend de charlesstolive/filament-ui (déclaré en `suggest`, comme
 * `CorrectionSidePane`).
 */
class AiInteractionsSidePane
{
    public const NAME = 'ai-interactions';

    /**
     * @param  Model|null  $trackable  Seulement ce qui a été demandé pour ce modèle ; `null` : toutes ses demandes.
     * @param  array<int, string>  $tasks  Seulement ces ressources ; vide : toutes.
     */
    public static function make(?Model $trackable = null, array $tasks = []): SidePane
    {
        return SidePane::make(self::NAME)
            ->label('Demandes IA')
            ->icon('heroicon-o-sparkles')
            ->schema([AiInteractionList::make($trackable, $tasks, layout: AiInteractionList::LAYOUT_PANE)]);
    }

    /** @param  array<int, string>  $tasks */
    public static function forTrackable(Model $trackable, array $tasks = []): SidePane
    {
        return static::make($trackable, $tasks);
    }
}
