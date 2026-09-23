<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Renderer par défaut : un diff mot-à-mot par champ corrigé, en deux blocs
 * « Avant » / « Après » (voir text-diff.blade.php). Les champs HTML sont
 * comparés à leur texte lisible (`WordDiff::textOf()`) — la mise en forme
 * n'est pas ce que corrige l'IA. `textOf()` accepte aussi une valeur non
 * scalaire (une interaction en base d'avant `CorrectableField::sanitizeValues()`)
 * sans lever « Array to string conversion ».
 */
class TextDiffRenderer implements AiResultRenderer
{
    public function render(AiInteraction $interaction): View
    {
        $input = $interaction->input ?? [];
        $output = $interaction->output ?? [];
        $labels = $interaction->meta['labels'] ?? [];

        $fields = collect($output)
            ->keys()
            ->map(function (string $field) use ($input, $output, $labels): array {
                $before = WordDiff::textOf($input[$field] ?? '');
                $after = WordDiff::textOf($output[$field] ?? '');

                return [
                    'field' => $field,
                    'label' => $labels[$field] ?? Str::headline($field),
                    'unchanged' => $before === $after,
                    'segments' => WordDiff::compare($before, $after),
                ];
            })
            ->values();

        return view('filament-prism::livewire.text-diff', ['fields' => $fields]);
    }

}
