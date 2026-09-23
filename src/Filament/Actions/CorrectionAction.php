<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Filament\Actions\Concerns\InteractsWithCorrectionTask;
use CharlesStOlive\FilamentPrism\Livewire\CorrectionReview;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use Closure;
use Filament\Actions\Action;
use RuntimeException;

/**
 * Ouvre la revue de correction dans une modale (variante « popup ») :
 *
 *     // Un modèle Correctable, écrit et sauvegardé directement à l'application :
 *     CorrectionAction::make()->correctable(fn () => $this->article)
 *
 *     // Un FieldsCorrectionSubject (ex. état Livewire) : rien à écrire tout seul,
 *     // voir autoApply() et l'événement filament-prism:correction-applied.
 *     CorrectionAction::make()
 *         ->correctable(fn () => FieldsCorrectionSubject::make(...))
 *         ->autoApply(false)
 */
class CorrectionAction extends Action
{
    use InteractsWithCorrectionTask;

    protected CorrectionSubject|Closure|null $correctable = null;

    protected bool|Closure $autoApply = true;

    public static function getDefaultName(): ?string
    {
        return 'aiCorrection';
    }

    public function correctable(CorrectionSubject|Closure $correctable): static
    {
        $this->correctable = $correctable;

        return $this;
    }

    /**
     * `false` quand le sujet ne sait pas écrire lui-même son résultat (voir
     * `CorrectionSubject`) : « Appliquer » se contente alors de marquer
     * l'interaction et d'envoyer les valeurs choisies à qui a ouvert l'action.
     */
    public function autoApply(bool|Closure $autoApply): static
    {
        $this->autoApply = $autoApply;

        return $this;
    }

    protected function getSubject(): CorrectionSubject
    {
        $subject = $this->evaluate($this->correctable);

        if (! $subject instanceof CorrectionSubject) {
            throw new RuntimeException('CorrectionAction::correctable() doit recevoir un CorrectionSubject (un modèle Correctable, ou un FieldsCorrectionSubject).');
        }

        return $subject;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCorrectionModal('Orthographe', fn () => CorrectionReview::forSubject(
            $this->getSubject(),
            $this->getTaskKey(),
            $this->getTrackable(),
            $this->evaluate($this->autoApply),
        ));
    }
}
