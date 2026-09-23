<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Livewire\CorrectionReview;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ouvre la revue de correction dans une modale (variante « popup ») :
 *
 *     // Un modèle Correctable, écrit et sauvegardé directement à l'application :
 *     CorrectionAction::make()->correctable(fn () => $this->period)
 *
 *     // Un FieldsCorrectionSubject (ex. état Livewire) : rien à écrire tout seul,
 *     // voir autoApply() et l'événement filament-prism:correction-applied.
 *     CorrectionAction::make()
 *         ->correctable(fn () => FieldsCorrectionSubject::make(...))
 *         ->autoApply(false)
 *
 * Désactivée toute seule quand le provider de la tâche n'a pas de clé API
 * configurée (voir `CorrectionService::providerIsConfigured()`) — inutile de
 * laisser cliquer sur un appel voué à échouer. Si l'appel échoue quand même
 * (clé refusée, réseau, quota...), la modale s'ouvre sur un message clair au
 * lieu de planter (voir `AiProviderException`).
 */
class CorrectionAction extends Action
{
    protected CorrectionSubject|Closure|null $correctable = null;

    protected string|Closure $taskKey = 'orthography';

    protected Model|Closure|null $trackable = null;

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

    protected function getTask(): AiTask
    {
        return app(AiTaskRegistry::class)->get($this->evaluate($this->taskKey));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Orthographe')
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
                    $review = CorrectionReview::forSubject(
                        $this->getSubject(),
                        $this->evaluate($this->taskKey),
                        $this->evaluate($this->trackable),
                        $this->evaluate($this->autoApply),
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
