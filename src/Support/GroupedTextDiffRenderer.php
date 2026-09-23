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
 * `CorrectionService::correctGroup()` filtre déjà chaque champ en chaîne
 * avant de persister (`CorrectableField::sanitizeValues()`) — voir la
 * docblock de `TextDiffRenderer::toText()`, même filet ici pour une
 * interaction déjà en base avant ce filtre.
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
                        $beforeText = self::toText($before[$field] ?? '');
                        $afterText = self::toText($item[$field] ?? '');

                        return [
                            'field' => $field,
                            'label' => $labels[$field] ?? Str::headline($field),
                            'unchanged' => strip_tags($beforeText) === strip_tags($afterText),
                            'segments' => WordDiff::compare(strip_tags($beforeText), strip_tags($afterText)),
                        ];
                    })
                    ->values();

                return ['key' => $item['key'], 'fields' => $fields];
            })
            ->values();

        return view('filament-prism::livewire.grouped-text-diff', [
            'interaction' => $interaction,
            'items' => $items,
        ]);
    }

    private static function toText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
