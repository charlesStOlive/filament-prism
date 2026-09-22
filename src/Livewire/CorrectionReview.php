<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
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
 *
 * `$autoApply` (sérialisable, contrairement à un `CorrectionSubject` qui peut
 * porter des closures) décide comment « Appliquer » écrit le résultat :
 * - `true` (par défaut) : le sujet est lui-même le modèle à corriger
 *   (`Correctable`) — `CorrectionService::apply()` y écrit et le sauvegarde.
 * - `false` : le sujet ne sait pas où vivent ses valeurs (`FieldsCorrectionSubject`,
 *   ex. un tableau d'état Livewire) — ce composant se contente de marquer
 *   l'interaction `applied` et d'envoyer les valeurs choisies à qui l'a ouvert,
 *   via l'événement `filament-prism:correction-applied`, à charge pour lui de
 *   les écrire (et de les persister, ou non) comme il l'entend.
 */
class CorrectionReview extends Component
{
    public int $interactionId;

    public bool $autoApply = true;

    /** @var array<string, bool> */
    public array $applyFields = [];

    /**
     * Déclenche (ou réutilise) la correction, puis pose ce composant tel
     * qu'on le glisse dans un schéma Filament (modale ou volet).
     */
    public static function forSubject(CorrectionSubject $subject, string $taskKey = 'orthography', ?Model $trackable = null, bool $autoApply = true): LivewireComponent
    {
        $interaction = app(CorrectionService::class)->correct($subject, $taskKey, $trackable);

        return LivewireComponent::make(static::class, [
            'interactionId' => $interaction->getKey(),
            'autoApply' => $autoApply,
        ])->key('filament-prism-correction-'.$interaction->getKey());
    }

    public function mount(int $interactionId, bool $autoApply = true): void
    {
        $this->interactionId = $interactionId;
        $this->autoApply = $autoApply;

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
        $this->applyValues(null);
    }

    public function applySelected(): void
    {
        $fields = collect($this->applyFields)->filter()->keys()->all();

        if ($fields === []) {
            Notification::make()->title('Aucun champ sélectionné')->warning()->send();

            return;
        }

        $this->applyValues($fields);
    }

    /** @param array<int, string>|null $onlyFields */
    private function applyValues(?array $onlyFields): void
    {
        $interaction = $this->interaction();

        if ($this->autoApply) {
            app(CorrectionService::class)->apply($interaction, $onlyFields);
            Notification::make()->title('Correction appliquée')->success()->send();

            return;
        }

        $values = $onlyFields === null
            ? ($interaction->output ?? [])
            : array_intersect_key($interaction->output ?? [], array_flip($onlyFields));

        $interaction->markApplied();
        $this->dispatch('filament-prism:correction-applied', interactionId: $interaction->getKey(), values: $values);
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
