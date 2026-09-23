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
                $before = self::plainText($input[$field] ?? '');
                $after = self::plainText($output[$field] ?? '');

                return [
                    'field' => $field,
                    'label' => $labels[$field] ?? Str::headline($field),
                    'unchanged' => $before === $after,
                    'segments' => WordDiff::compare($before, $after),
                ];
            })
            ->values();

        return view('filament-prism::livewire.text-diff', [
            'interaction' => $interaction,
            'fields' => $fields,
        ]);
    }

    /**
     * Le texte réellement lisible d'un champ (HTML ou non) : balises retirées, puis entités décodées
     * (`&#039;` -> `'`) — sans ça, une apostrophe encodée dans le HTML source resterait telle quelle,
     * puis Blade (`{{ }}`, à raison, pour l'affichage) l'échapperait une seconde fois : l'entité
     * apparaîtrait en toutes lettres à l'écran au lieu du caractère qu'elle représente.
     */
    private static function plainText(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
    }
}
