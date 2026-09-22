<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Livewire\CorrectionReview;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Ouvre la revue de correction dans une modale (variante « popup ») :
 *
 *     CorrectionAction::make()->correctable(fn () => $this->period)
 */
class CorrectionAction extends Action
{
    protected Model|Closure|null $correctable = null;

    protected string|Closure $taskKey = 'orthography';

    protected Model|Closure|null $trackable = null;

    public static function getDefaultName(): ?string
    {
        return 'aiCorrection';
    }

    public function correctable(Model|Closure $correctable): static
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

    protected function getCorrectable(): Model
    {
        $correctable = $this->evaluate($this->correctable);

        if (! $correctable instanceof Model || ! in_array(Correctable::class, class_uses_recursive($correctable), true)) {
            throw new RuntimeException('CorrectionAction::correctable() doit recevoir un modèle utilisant le trait Correctable.');
        }

        return $correctable;
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
            ->schema(fn (): array => [
                Section::make()->schema([
                    CorrectionReview::forCorrectable(
                        $this->getCorrectable(),
                        $this->evaluate($this->taskKey),
                        $this->evaluate($this->trackable),
                    ),
                ]),
            ]);
    }
}
