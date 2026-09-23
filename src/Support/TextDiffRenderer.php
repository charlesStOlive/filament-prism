<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Renderer par défaut : un diff mot-à-mot par champ corrigé, texte ajouté
 * souligné, texte retiré barré. Les champs HTML sont comparés à leur texte
 * brut (`strip_tags`) — la mise en forme n'est pas ce que corrige l'IA.
 *
 * `CorrectionService` filtre déjà chaque champ en chaîne avant de persister
 * (`CorrectableField::sanitizeValues()`) : la valeur brute d'un provider peu
 * scrupuleux (`[]` au lieu de `''`, par exemple) ne devrait donc jamais
 * arriver ici. Le filet ci-dessous (`toText()`) reste utile pour une
 * interaction déjà en base avant ce filtre — sans lui, `(string)` sur un
 * tableau lève une `ErrorException` (« Array to string conversion »).
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
                $before = self::toText($input[$field] ?? '');
                $after = self::toText($output[$field] ?? '');

                return [
                    'field' => $field,
                    'label' => $labels[$field] ?? Str::headline($field),
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

    private static function toText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
