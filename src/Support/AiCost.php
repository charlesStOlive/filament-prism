<?php

namespace CharlesStOlive\FilamentPrism\Support;

/**
 * Ce qu'un appel a coûté, d'après la table de prix de la config
 * (`filament-prism.pricing`, prix pour un million de tokens). Calculé et figé
 * au moment de l'appel : un changement de tarif ne réécrit pas l'historique.
 *
 * Un modèle absent de la table n'a pas de coût (`null`) : les stats le
 * montrent comme inconnu plutôt que gratuit.
 *
 * Les modèles d'image comptent à part les tokens d'image envoyés (les photos
 * jointes), plus chers que ceux du texte : quand le provider en donne le
 * détail (`input_tokens_details.image_tokens`) et que le modèle a un prix
 * `input_image`, cette part-là est comptée à ce prix.
 */
final class AiCost
{
    /** @param  array<string, mixed>  $inputDetails  Le détail des tokens envoyés, tel que le provider le donne. */
    public static function of(string $model, int $promptTokens, int $completionTokens, array $inputDetails = []): ?float
    {
        $prices = config("filament-prism.pricing.{$model}");

        if (! is_array($prices) || ! isset($prices['input'], $prices['output'])) {
            return null;
        }

        $imageTokens = isset($prices['input_image']) ? min($promptTokens, (int) ($inputDetails['image_tokens'] ?? 0)) : 0;
        $textTokens = $promptTokens - $imageTokens;

        $cost = $textTokens * (float) $prices['input']
            + $imageTokens * (float) ($prices['input_image'] ?? 0)
            + $completionTokens * (float) $prices['output'];

        return round($cost / 1_000_000, 6);
    }
}
