<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Livewire\GroupCorrectionReview;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ouvre la revue de correction d'un groupe de sujets dans une modale — par
 * exemple, tout un voyage en un seul appel IA :
 *
 *     GroupCorrectionAction::make()->subjects(fn () => CorrectionSubjectGroup::make(...))
 *
 * Toujours en écoute d'événement, jamais d'écriture directe (voir
 * `GroupCorrectionReview`) : pas d'équivalent `autoApply` ici. Désactivée
 * toute seule sans clé API configurée, message propre si l'appel échoue quand
 * même — même garde-fou que `CorrectionAction`, voir sa docblock.
 */
class GroupCorrectionAction extends Action
{
    /**
     * Pas nommé `group` : `Filament\Actions\Action` porte déjà `group(?ActionGroup $group)`
     * (regroupement d'actions dans un menu) — un même nom avec une signature
     * incompatible y déclare une erreur fatale de classe, détectée au premier
     * rendu de la page seulement (pas au chargement de la classe).
     */
    protected CorrectionSubjectGroup|Closure|null $subjectGroup = null;

    protected string|Closure $taskKey = 'orthography';

    protected Model|Closure|null $trackable = null;

    public static function getDefaultName(): ?string
    {
        return 'aiGroupCorrection';
    }

    public function subjects(CorrectionSubjectGroup|Closure $group): static
    {
        $this->subjectGroup = $group;

        return $this;
    }

    public function taskKey(string|Closure $taskKey): static
    {
        $this->taskKey = $taskKey;

        return $this;
    }

    public function trackable(Model|Closure|null $trackable): static
    {
        $this->trackable = $trackable;

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

    protected function getTask(): AiTask
    {
        return app(AiTaskRegistry::class)->get($this->evaluate($this->taskKey));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Corriger tout')
            ->icon('heroicon-o-sparkles')
            ->modalHeading('Correction orthographique')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->disabled(fn (): bool => ! app(CorrectionService::class)->providerIsConfigured($this->getTask()->provider()))
            ->tooltip(fn (): ?string => app(CorrectionService::class)->providerIsConfigured($this->getTask()->provider())
                ? null
                : 'Clé API manquante pour ce provider — voir le fichier .env.')
            ->schema(function (): array {
                try {
                    $review = GroupCorrectionReview::forGroup(
                        $this->getSubjectGroup(),
                        $this->evaluate($this->taskKey),
                        $this->evaluate($this->trackable),
                    );
                } catch (AiProviderException $exception) {
                    return [
                        Placeholder::make('aiProviderError')
                            ->label('Correction impossible')
                            ->content($exception->getMessage())
                            ->columnSpanFull(),
                    ];
                }

                return [Section::make()->schema([$review])];
            });
    }
}
