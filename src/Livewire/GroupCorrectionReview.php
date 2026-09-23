<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use CharlesStOlive\FilamentPrism\Support\GroupedTextDiffRenderer;
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
    public int $interactionId;

    /** @var array<string, bool> */
    public array $applyItems = [];

    public static function forGroup(CorrectionSubjectGroup $group, string $taskKey = 'orthography', ?Model $trackable = null): LivewireComponent
    {
        $interaction = app(CorrectionService::class)->correctGroup($group, $taskKey, $trackable);

        return LivewireComponent::make(static::class, ['interactionId' => $interaction->getKey()])
            ->key('filament-prism-group-correction-'.$interaction->getKey());
    }

    public function mount(int $interactionId): void
    {
        $this->interactionId = $interactionId;

        $this->applyItems = collect($this->interaction()->output['items'] ?? [])
            ->pluck('key')
            ->filter()
            ->mapWithKeys(fn (string $key): array => [$key => true])
            ->all();
    }

    public function interaction(): AiInteraction
    {
        return AiInteraction::findOrFail($this->interactionId);
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

        $values = collect($interaction->output['items'] ?? [])
            ->filter(fn (array $item): bool => in_array($item['key'] ?? null, $onlyKeys, true))
            ->mapWithKeys(fn (array $item): array => [$item['key'] => collect($item)->except('key')->all()])
            ->all();

        $interaction->markApplied();
        $this->dispatch('filament-prism:group-correction-applied', interactionId: $interaction->getKey(), values: $values);

        Notification::make()->title('Correction appliquée')->success()->send();
    }

    public function discard(): void
    {
        app(CorrectionService::class)->discard($this->interaction());

        Notification::make()->title('Correction ignorée')->body('Aucun sujet n’a été modifié.')->warning()->send();
    }

    public function render(): View
    {
        $interaction = $this->interaction();

        $inputByKey = collect($interaction->input['items'] ?? [])
            ->filter(fn (array $item): bool => isset($item['key']))
            ->keyBy('key')
            ->all();

        return view('filament-prism::livewire.group-correction-review', [
            'rendererView' => app(GroupedTextDiffRenderer::class)->render($interaction, $inputByKey),
        ]);
    }
}
