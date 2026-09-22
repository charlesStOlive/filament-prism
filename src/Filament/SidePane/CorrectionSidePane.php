<?php

namespace CharlesStOlive\FilamentPrism\Filament\SidePane;

use CharlesStOlive\FilamentOrchestrator\Filament\Split\SidePane;
use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Livewire\CorrectionReview;
use Illuminate\Database\Eloquent\Model;

/**
 * Même revue de correction que `CorrectionAction`, posée dans le volet
 * latéral extensible de filament-orchestrator (`HasSidePane`) plutôt qu'en
 * modale — pas besoin d'un second mécanisme de volet, celui-là suffit.
 *
 * Dépend de charlesstolive/filament-orchestrator (déclaré en `suggest`, pas
 * en dépendance dure : cette classe n'est chargée que si l'application
 * l'appelle explicitement).
 */
class CorrectionSidePane
{
    public static function make(
        string $name,
        Model&Correctable $correctable,
        string $taskKey = 'orthography',
        ?Model $trackable = null,
    ): SidePane {
        return SidePane::make($name)
            ->label('Orthographe')
            ->icon('heroicon-o-sparkles')
            ->schema([
                CorrectionReview::forCorrectable($correctable, $taskKey, $trackable),
            ]);
    }
}
