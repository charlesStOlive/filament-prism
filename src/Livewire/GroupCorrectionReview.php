<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Livewire\Concerns\ReviewsCorrection;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use CharlesStOlive\FilamentPrism\Support\GroupedTextDiffRenderer;
use CharlesStOlive\FilamentPrism\Tasks\OrthographyTask;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Le pendant de `CorrectionReview` pour une correction de groupe (voir
 * `CorrectionService::correctGroup()`) : un sujet parmi plusieurs (une
 * période parmi tout un voyage) n'a ni colonne ni `save()` génériques —
 * « Appliquer » envoie toujours les sujets choisis à qui a ouvert l'action,
 * via l'événement `filament-prism:group-correction-applied`, jamais
 * d'écriture directe (pas d'équivalent `autoApply` ici).
 */
class GroupCorrectionReview extends Component
{
    use ReviewsCorrection;

    /** @var array<string, bool> */
    public array $applyItems = [];

    public static function forGroup(CorrectionSubjectGroup $group, string $taskKey = 'orthography', ?Model $trackable = null): LivewireComponent
    {
        $interaction = app(CorrectionService::class)->prepareGroup($group, $taskKey, $trackable);

        return static::forInteraction($interaction->getKey());
    }

    /** La revue d'une correction de groupe déjà demandée. */
    public static function forInteraction(int $interactionId): LivewireComponent
    {
        return LivewireComponent::make(static::class, ['interactionId' => $interactionId])
            ->key('filament-prism-group-correction-'.$interactionId);
    }

    public function mount(int $interactionId): void
    {
        $this->interactionId = $interactionId;
        $this->mountFor($this->interaction());
    }

    protected function mountFor(AiInteraction $interaction): void
    {
        $this->applyItems = collect($interaction->output['items'] ?? [])
            ->pluck('key')
            ->filter()
            ->mapWithKeys(fn (string $key): array => [$key => true])
            ->all();
    }

    protected function nothingChangedMessage(): string
    {
        return 'Aucun sujet n’a été modifié.';
    }

    public function applyAll(): void
    {
        $this->applyValues(array_keys($this->applyItems));
    }

    public function applySelected(): void
    {
        $keys = collect($this->applyItems)->filter()->keys()->all();

        if ($keys === []) {
            Notification::make()->title('Aucun sujet sélectionné')->warning()->send();

            return;
        }

        $this->applyValues($keys);
    }

    /** @param array<int, string> $onlyKeys */
    private function applyValues(array $onlyKeys): void
    {
        $interaction = $this->interaction();

        // Déjà appliquée ou ignorée (un second clic parti avant le redessin) : rien à refaire.
        if (! $interaction->isStatus(AiInteraction::STATUS_PENDING)) {
            return;
        }

        $values = collect($interaction->output['items'] ?? [])
            ->filter(fn (array $item): bool => in_array($item['key'] ?? null, $onlyKeys, true))
            ->mapWithKeys(fn (array $item): array => [$item['key'] => collect($item)->except('key')->all()])
            ->all();

        $interaction->markApplied();
        $this->dispatch('filament-prism:group-correction-applied', interactionId: $interaction->getKey(), values: $values);

        Notification::make()->title('Correction appliquée')->success()->send();
    }

    public function render(): View
    {
        $interaction = $this->interaction();

        $inputByKey = collect($interaction->input['items'] ?? [])
            ->filter(fn (array $item): bool => isset($item['key']))
            ->keyBy('key')
            ->all();

        return view('filament-prism::livewire.group-correction-review', [
            'rendererView' => $interaction->hasResult() ? app(GroupedTextDiffRenderer::class)->render($interaction, $inputByKey) : null,
            'interaction' => $interaction,
            'draftSubjects' => $interaction->isDraft() ? OrthographyTask::draftSubjects($interaction) : [],
        ]);
    }
}
