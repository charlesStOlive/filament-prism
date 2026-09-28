<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions\Concerns;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use Closure;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Ce que partagent `CorrectionAction` et `GroupCorrectionAction` : la tâche IA, le périmètre de
 * suivi des tokens, la modale, et le garde-fou sur la clé API — bouton désactivé (avec un
 * tooltip) tant que le provider de la tâche n'est pas configuré, message clair dans la modale si
 * l'appel échoue quand même (clé refusée, réseau, quota…).
 */
trait InteractsWithCorrectionTask
{
    /** L'argument de l'action ouverte qui garde la demande de correction (voir `setUpCorrectionModal()`). */
    public const INTERACTION_ARGUMENT = 'interaction';

    protected string|Closure $taskKey = 'orthography';

    protected Model|Closure|null $trackable = null;

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

    protected function getTaskKey(): string
    {
        return $this->evaluate($this->taskKey);
    }

    protected function getTrackable(): ?Model
    {
        return $this->evaluate($this->trackable);
    }

    protected function isProviderConfigured(): bool
    {
        $provider = app(AiTaskRegistry::class)->get($this->getTaskKey())->provider();

        return app(CorrectionService::class)->providerIsConfigured($provider);
    }

    /**
     * La modale s'ouvre sur un brouillon — les textes qui partiront —, ou sur la réponse déjà payée
     * pour ce même texte : rien n'est envoyé à l'IA avant « Soumettre » (voir `ReviewsCorrection`).
     * L'id de la demande est gardé dans les arguments de l'action montée : Filament reconstruit le
     * schéma d'une action ouverte à chaque requête de la page (celle qu'« Appliquer » provoque en
     * prévenant la page, par exemple), et la revue doit rester sur sa demande — auparavant, chaque
     * requête relançait une correction, un appel payé de plus (il fallait « cliquer deux fois »).
     *
     * @param  Closure(): AiInteraction  $startCorrection  Prépare la correction (brouillon, ou réponse déjà payée).
     * @param  Closure(int): LivewireComponent  $makeReview  Pose la revue d'une demande.
     */
    protected function setUpCorrectionModal(string $label, Closure $startCorrection, Closure $makeReview): void
    {
        $this
            ->label($label)
            ->icon('heroicon-o-sparkles')
            ->modalHeading('Correction orthographique')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->disabled(fn (): bool => ! $this->isProviderConfigured())
            ->tooltip(fn (): ?string => $this->isProviderConfigured() ? null : 'Clé API manquante pour ce provider — voir le fichier .env.')
            ->schema(function (array $arguments, HasActions $livewire) use ($startCorrection, $makeReview): array {
                try {
                    $interactionId = $arguments[self::INTERACTION_ARGUMENT] ?? null;

                    if ($interactionId === null) {
                        $interactionId = $startCorrection()->getKey();
                        $livewire->mergeMountedActionArguments([self::INTERACTION_ARGUMENT => $interactionId]);
                    }

                    return [Section::make()->schema([$makeReview((int) $interactionId)])];
                } catch (AiProviderException $exception) {
                    return [
                        Placeholder::make('aiProviderError')
                            ->label('Correction impossible')
                            ->content($exception->getMessage())
                            ->columnSpanFull(),
                    ];
                }
            });
    }
}
