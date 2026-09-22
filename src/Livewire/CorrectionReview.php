<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Composant partagé, utilisable en popup (`CorrectionAction`) ou en volet
 * (`CorrectionSidePane`) : piloté par l'id d'une `AiInteraction`, jamais par
 * des tableaux avant/après passés en props — la réponse IA a déjà été
 * persistée avant que ce composant existe.
 */
class CorrectionReview extends Component
{
    public int $interactionId;

    /** @var array<string, bool> */
    public array $applyFields = [];

    /**
     * Déclenche (ou réutilise) la correction, puis pose ce composant tel
     * qu'on le glisse dans un schéma Filament (modale ou volet).
     */
    public static function forCorrectable(Model&Correctable $correctable, string $taskKey = 'orthography', ?Model $trackable = null): LivewireComponent
    {
        $interaction = app(CorrectionService::class)->correct($correctable, $taskKey, $trackable);

        return LivewireComponent::make(static::class, ['interactionId' => $interaction->getKey()])
            ->key('filament-prism-correction-'.$interaction->getKey());
    }

    public function mount(int $interactionId): void
    {
        $this->interactionId = $interactionId;

        $this->applyFields = collect($this->interaction()->output ?? [])
            ->keys()
            ->mapWithKeys(fn (string $field): array => [$field => true])
            ->all();
    }

    public function interaction(): AiInteraction
    {
        return AiInteraction::findOrFail($this->interactionId);
    }

    public function applyAll(): void
    {
        app(CorrectionService::class)->apply($this->interaction());

        Notification::make()->title('Correction appliquée')->success()->send();
    }

    public function applySelected(): void
    {
        $fields = collect($this->applyFields)->filter()->keys()->all();

        if ($fields === []) {
            Notification::make()->title('Aucun champ sélectionné')->warning()->send();

            return;
        }

        app(CorrectionService::class)->apply($this->interaction(), $fields);

        Notification::make()->title('Correction appliquée')->success()->send();
    }

    public function discard(): void
    {
        app(CorrectionService::class)->discard($this->interaction());

        Notification::make()->title('Correction ignorée')->body('Aucun champ n’a été modifié.')->warning()->send();
    }

    public function render(): View
    {
        $interaction = $this->interaction();
        $task = app(AiTaskRegistry::class)->get($interaction->task);
        $renderer = app($task->rendererClass());

        return view('filament-prism::livewire.correction-review', [
            'interaction' => $interaction,
            'rendererView' => $renderer->render($interaction),
        ]);
    }
}
