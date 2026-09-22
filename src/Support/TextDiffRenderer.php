<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Illuminate\Contracts\View\View;

/**
 * Renderer par défaut : un diff mot-à-mot par champ corrigé, texte ajouté
 * souligné, texte retiré barré. Les champs HTML sont comparés à leur texte
 * brut (`strip_tags`) — la mise en forme n'est pas ce que corrige l'IA.
 */
class TextDiffRenderer implements AiResultRenderer
{
    public function render(AiInteraction $interaction): View
    {
        $input = $interaction->input ?? [];
        $output = $interaction->output ?? [];

        $fields = collect($output)
            ->keys()
            ->map(function (string $field) use ($input, $output): array {
                $before = (string) ($input[$field] ?? '');
                $after = (string) ($output[$field] ?? '');

                return [
                    'field' => $field,
                    'unchanged' => strip_tags($before) === strip_tags($after),
                    'segments' => WordDiff::compare(strip_tags($before), strip_tags($after)),
                ];
            })
            ->values();

        return view('filament-prism::livewire.text-diff', [
            'interaction' => $interaction,
            'fields' => $fields,
        ]);
    }
}
