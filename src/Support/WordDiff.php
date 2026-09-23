<?php

namespace CharlesStOlive\FilamentPrism\Support;

/**
 * Diff mot-à-mot minimal (plus longue sous-séquence commune), sans dépendance
 * JS : le rendu (surlignage des écarts) reste côté PHP/Blade.
 *
 * Un saut de paragraphe est un mot à part entière (`"\n"`) : il s'aligne
 * comme les autres, et la vue le rend en retour à la ligne.
 */
class WordDiff
{
    public const LINE_BREAK = "\n";

    /**
     * Le texte réellement lisible d'un champ, HTML ou non : les fins de bloc
     * (`</p>`, `<br>`, `</li>`…) deviennent des sauts de ligne — sans quoi
     * deux paragraphes se colleraient (« signalerAvec ») —, puis les balises
     * sont retirées et les entités décodées (`&#039;` -> `'`). Sans ce
     * décodage, une entité du HTML source serait échappée une seconde fois
     * par Blade à l'affichage et apparaîtrait en toutes lettres.
     */
    public static function textOf(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = preg_replace('#<br\s*/?>|</(?:p|div|li|h[1-6]|blockquote)>#i', self::LINE_BREAK, $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        $lines = array_map(fn (string $line): string => trim(preg_replace('/[^\S\n]+/u', ' ', $line) ?? $line), explode(self::LINE_BREAK, $text));

        return implode(self::LINE_BREAK, array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<int, array{type: 'same'|'removed'|'added', text: string}>
     */
    public static function compare(string $before, string $after): array
    {
        $a = self::split($before);
        $b = self::split($after);
        $m = count($a);
        $n = count($b);

        // Longueur de la plus longue sous-séquence commune des suffixes a[x..] et b[y..] : on peut
        // ensuite parcourir les deux textes dans l'ordre de lecture en suivant toujours le meilleur
        // alignement, plutôt que d'apparier un mot à sa première occurrence (qui décalerait les
        // mots répétés, « de », « la »…).
        $table = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($x = $m - 1; $x >= 0; $x--) {
            for ($y = $n - 1; $y >= 0; $y--) {
                $table[$x][$y] = $a[$x] === $b[$y]
                    ? $table[$x + 1][$y + 1] + 1
                    : max($table[$x + 1][$y], $table[$x][$y + 1]);
            }
        }

        $segments = [];
        $x = 0;
        $y = 0;

        while ($x < $m && $y < $n) {
            if ($a[$x] === $b[$y]) {
                $segments[] = ['type' => 'same', 'text' => $a[$x]];
                $x++;
                $y++;
            } elseif ($table[$x + 1][$y] >= $table[$x][$y + 1]) {
                $segments[] = ['type' => 'removed', 'text' => $a[$x]];
                $x++;
            } else {
                $segments[] = ['type' => 'added', 'text' => $b[$y]];
                $y++;
            }
        }

        for (; $x < $m; $x++) {
            $segments[] = ['type' => 'removed', 'text' => $a[$x]];
        }

        for (; $y < $n; $y++) {
            $segments[] = ['type' => 'added', 'text' => $b[$y]];
        }

        return $segments;
    }

    /** @return array<int, string> Les mots, et chaque saut de ligne comme un mot à part. */
    private static function split(string $text): array
    {
        return preg_split('/(\n)|[^\S\n]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];
    }
}
