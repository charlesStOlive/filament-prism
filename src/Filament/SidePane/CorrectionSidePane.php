<?php

namespace CharlesStOlive\FilamentPrism\Filament\SidePane;

use CharlesStOlive\FilamentPrism\Livewire\CorrectionReview;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use CharlesStOlive\FilamentUi\Split\SidePane;
use Illuminate\Database\Eloquent\Model;

/**
 * Même revue de correction que `CorrectionAction`, posée dans le volet
 * latéral de filament-ui (`HasSidePane`) plutôt qu'en modale — pas besoin
 * d'un second mécanisme de volet, celui-là suffit.
 *
 * Dépend de charlesstolive/filament-ui (déclaré en `suggest`, pas en
 * dépendance dure : cette classe n'est chargée que si l'application
 * l'appelle explicitement).
 */
class CorrectionSidePane
{
    public static function make(
        string $name,
        CorrectionSubject $subject,
        string $taskKey = 'orthography',
        ?Model $trackable = null,
        bool $autoApply = true,
    ): SidePane {
        return SidePane::make($name)
            ->label('Orthographe')
            ->icon('heroicon-o-sparkles')
            ->schema([
                CorrectionReview::forSubject($subject, $taskKey, $trackable, $autoApply),
            ]);
    }
}
