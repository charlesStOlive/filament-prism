<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Filament\Actions\Concerns\InteractsWithCorrectionTask;
use CharlesStOlive\FilamentPrism\Livewire\GroupCorrectionReview;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use Closure;
use Filament\Actions\Action;
use RuntimeException;

/**
 * Ouvre la revue de correction d'un groupe de sujets dans une modale — par
 * exemple, tout un voyage en un seul appel IA :
 *
 *     GroupCorrectionAction::make()->subjects(fn () => CorrectionSubjectGroup::make(...))
 *
 * Toujours en écoute d'événement, jamais d'écriture directe (voir
 * `GroupCorrectionReview`) : pas d'équivalent `autoApply` ici.
 */
class GroupCorrectionAction extends Action
{
    use InteractsWithCorrectionTask;

    /**
     * Pas nommé `group` : `Filament\Actions\Action` porte déjà `group(?ActionGroup $group)` —
     * une signature incompatible sous le même nom est une erreur fatale, levée seulement au
     * premier rendu de la page (pas au chargement de la classe).
     */
    protected CorrectionSubjectGroup|Closure|null $subjectGroup = null;

    public static function getDefaultName(): ?string
    {
        return 'aiGroupCorrection';
    }

    public function subjects(CorrectionSubjectGroup|Closure $group): static
    {
        $this->subjectGroup = $group;

        return $this;
    }

    protected function getSubjectGroup(): CorrectionSubjectGroup
    {
        $group = $this->evaluate($this->subjectGroup);

        if (! $group instanceof CorrectionSubjectGroup) {
            throw new RuntimeException('GroupCorrectionAction::subjects() doit recevoir un CorrectionSubjectGroup.');
        }

        return $group;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCorrectionModal('Corriger tout', fn () => GroupCorrectionReview::forGroup(
            $this->getSubjectGroup(),
            $this->getTaskKey(),
            $this->getTrackable(),
        ));
    }
}
