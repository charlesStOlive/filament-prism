<?php

namespace CharlesStOlive\FilamentPrism\Support;

/**
 * Un montant lisible, même minuscule : une requête IA coûte souvent une
 * fraction de centime, qu'un arrondi à 2 ou 3 décimales écraserait à « 0,00 € ».
 *
 * Sous 1, trois chiffres significatifs (0,0125 € ; 0,000316 €), jusqu'à la
 * précision stockée (8 décimales) ; à partir de 1, deux décimales (12,40 €).
 */
final class AiMoney
{
    public const PRECISION = 8;

    public static function format(?float $amount, string $currency = 'EUR'): ?string
    {
        if ($amount === null) {
            return null;
        }

        $symbol = match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => strtoupper($currency),
        };

        return self::number($amount)."\u{00A0}".$symbol;
    }

    public static function number(float $amount): string
    {
        $absolute = abs($amount);

        if ($absolute == 0.0) {
            return '0';
        }

        $decimals = $absolute >= 1
            ? 2
            : min(self::PRECISION, max(2, (int) -floor(log10($absolute)) + 2));

        return number_format($amount, $decimals, ',', "\u{202F}");
    }
}
