<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Livewire\Concerns\ReviewsCorrection;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use CharlesStOlive\FilamentPrism\Tasks\OrthographyTask;
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
 *   l'interaction acceptée (et appliquée) et d'envoyer les valeurs choisies à qui l'a ouvert,
 *   via l'événement `filament-prism:correction-applied`, à charge pour lui de
 *   les écrire (et de les persister, ou non) comme il l'entend.
 */
class CorrectionReview extends Component
{
    use ReviewsCorrection;

    public bool $autoApply = true;

    /** @var array<string, bool> */
    public array $applyFields = [];

    /**
     * Prépare la correction (un brouillon, ou la réponse déjà payée pour ce même texte), puis pose
     * ce composant tel qu'on le glisse dans un schéma Filament (modale ou volet). Rien n'est envoyé
     * à l'IA avant « Soumettre ».
     */
    public static function forSubject(CorrectionSubject $subject, string $taskKey = 'orthography', ?Model $trackable = null, bool $autoApply = true): LivewireComponent
    {
        $interaction = app(CorrectionService::class)->prepare($subject, $taskKey, $trackable);

        return static::forInteraction($interaction->getKey(), $autoApply);
    }

    /** La revue d'une correction déjà demandée. */
    public static function forInteraction(int $interactionId, bool $autoApply = true): LivewireComponent
    {
        return LivewireComponent::make(static::class, [
            'interactionId' => $interactionId,
            'autoApply' => $autoApply,
        ])->key('filament-prism-correction-'.$interactionId);
    }

    public function mount(int $interactionId, bool $autoApply = true): void
    {
        $this->interactionId = $interactionId;
        $this->autoApply = $autoApply;
        $this->mountFor($this->interaction());
    }

    protected function mountFor(AiInteraction $interaction): void
    {
        $this->applyFields = collect($interaction->output ?? [])
            ->keys()
            ->mapWithKeys(fn (string $field): array => [$field => true])
            ->all();
    }

    protected function nothingChangedMessage(): string
    {
        return 'Aucun champ n’a été modifié.';
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

        // Déjà appliquée ou ignorée (un second clic parti avant le redessin) : rien à refaire.
        if (! $interaction->isStatus(AiInteraction::STATUS_PENDING)) {
            return;
        }

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

    public function render(): View
    {
        $interaction = $this->interaction();
        $task = app(AiTaskRegistry::class)->get($interaction->task);

        return view('filament-prism::livewire.correction-review', [
            'rendererView' => $interaction->hasResult() ? app($task->rendererClass())->render($interaction) : null,
            'interaction' => $interaction,
            'draftSubjects' => $interaction->isDraft() ? OrthographyTask::draftSubjects($interaction) : [],
        ]);
    }
}
