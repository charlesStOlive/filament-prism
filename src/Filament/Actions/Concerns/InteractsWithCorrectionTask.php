<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions\Concerns;

use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use Closure;
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

    /** @param  Closure(): LivewireComponent  $makeReview  Lance (ou réutilise) la correction et pose sa revue. */
    protected function setUpCorrectionModal(string $label, Closure $makeReview): void
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
            ->schema(function () use ($makeReview): array {
                try {
                    return [Section::make()->schema([$makeReview()])];
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
