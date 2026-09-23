<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Le pendant de `TextDiffRenderer` pour une correction de groupe
 * (`CorrectionService::correctGroup()`) : un diff mot-à-mot par champ,
 * regroupé par sujet plutôt qu'affiché à plat. `output.items` associe
 * chaque correction à son sujet par sa `key`, pas par position.
 *
 * Contrairement à `AiResultRenderer`, ce renderer n'est pas résolu via
 * `AiTask::rendererClass()` : la forme d'une réponse groupée est fixe
 * (toujours `{items: [...]}`), indépendante de la tâche — `GroupCorrectionReview`
 * l'appelle donc directement.
 *
 * Même extraction de texte que `TextDiffRenderer` (`WordDiff::textOf()`).
 */
class GroupedTextDiffRenderer
{
    /** @param array<string, array<string, mixed>> $inputByKey */
    public function render(AiInteraction $interaction, array $inputByKey): View
    {
        $labels = $interaction->meta['labels'] ?? [];

        $items = collect($interaction->output['items'] ?? [])
            ->filter(fn (array $item): bool => isset($item['key'], $inputByKey[$item['key']]))
            ->map(function (array $item) use ($inputByKey, $labels): array {
                $before = $inputByKey[$item['key']];

                $fields = collect($item)
                    ->except(['key'])
                    ->keys()
                    ->map(function (string $field) use ($before, $item, $labels): array {
                        $beforeText = WordDiff::textOf($before[$field] ?? '');
                        $afterText = WordDiff::textOf($item[$field] ?? '');

                        return [
                            'field' => $field,
                            'label' => $labels[$field] ?? Str::headline($field),
                            'unchanged' => $beforeText === $afterText,
                            'segments' => WordDiff::compare($beforeText, $afterText),
                        ];
                    })
                    ->values();

                return ['key' => $item['key'], 'fields' => $fields];
            })
            ->values();

        return view('filament-prism::livewire.grouped-text-diff', ['items' => $items]);
    }

}
