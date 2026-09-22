<?php

namespace CharlesStOlive\FilamentPrism\Support;

/**
 * Diff mot-à-mot minimal (plus longue sous-séquence commune), sans dépendance
 * JS : le rendu (surlignage rouge des écarts) reste côté PHP/Blade.
 */
class WordDiff
{
    /**
     * @return array<int, array{type: 'same'|'removed'|'added', text: string}>
     */
    public static function compare(string $before, string $after): array
    {
        $beforeWords = self::split($before);
        $afterWords = self::split($after);

        $lcs = self::longestCommonSubsequence($beforeWords, $afterWords);

        $segments = [];
        $i = 0;
        $j = 0;

        foreach ($lcs as $word) {
            while ($i < count($beforeWords) && $beforeWords[$i] !== $word) {
                $segments[] = ['type' => 'removed', 'text' => $beforeWords[$i]];
                $i++;
            }
            while ($j < count($afterWords) && $afterWords[$j] !== $word) {
                $segments[] = ['type' => 'added', 'text' => $afterWords[$j]];
                $j++;
            }
            $segments[] = ['type' => 'same', 'text' => $word];
            $i++;
            $j++;
        }

        while ($i < count($beforeWords)) {
            $segments[] = ['type' => 'removed', 'text' => $beforeWords[$i]];
            $i++;
        }
        while ($j < count($afterWords)) {
            $segments[] = ['type' => 'added', 'text' => $afterWords[$j]];
            $j++;
        }

        return $segments;
    }

    /** @return array<int, string> */
    private static function split(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, string>
     */
    private static function longestCommonSubsequence(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        $table = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($x = 1; $x <= $m; $x++) {
            for ($y = 1; $y <= $n; $y++) {
                $table[$x][$y] = $a[$x - 1] === $b[$y - 1]
                    ? $table[$x - 1][$y - 1] + 1
                    : max($table[$x - 1][$y], $table[$x][$y - 1]);
            }
        }

        $result = [];
        $x = $m;
        $y = $n;

        while ($x > 0 && $y > 0) {
            if ($a[$x - 1] === $b[$y - 1]) {
                array_unshift($result, $a[$x - 1]);
                $x--;
                $y--;
            } elseif ($table[$x - 1][$y] >= $table[$x][$y - 1]) {
                $x--;
            } else {
                $y--;
            }
        }

        return $result;
    }
}
