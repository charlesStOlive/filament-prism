<?php

namespace CharlesStOlive\FilamentPrism\Support;

/**
 * Ce qu'un appel a coûté selon les prix du catalogue (`filament-prism.providers`,
 * prix pour un million de tokens, dans la devise du fournisseur) — une
 * **estimation**, figée au moment de l'appel : un changement de tarif ne
 * réécrit pas l'historique. Ce que le fournisseur a réellement facturé vient de
 * son API de facturation (voir `Billing\AiBillingSync`), jour par jour.
 *
 * Un modèle sans prix n'a pas de coût (`null`) : les stats le montrent comme
 * inconnu plutôt que gratuit.
 *
 * Les modèles d'image comptent à part les tokens d'image envoyés (les photos
 * jointes), plus chers que ceux du texte : quand le provider en donne le
 * détail (`input_tokens_details.image_tokens`) et que le modèle a un prix
 * `input_image`, cette part-là est comptée à ce prix.
 */
final class AiCost
{
    /** @param  array<string, mixed>  $inputDetails  Le détail des tokens envoyés, tel que le provider le donne. */
    public static function of(string $provider, string $model, int $promptTokens, int $completionTokens, array $inputDetails = []): ?float
    {
        $prices = AiProviders::model($provider, $model);

        if (! is_array($prices) || ! is_numeric($prices['input'] ?? null) || ! is_numeric($prices['output'] ?? null)) {
            return null;
        }

        $imageTokens = is_numeric($prices['input_image'] ?? null) ? min($promptTokens, (int) ($inputDetails['image_tokens'] ?? 0)) : 0;
        $textTokens = $promptTokens - $imageTokens;

        $cost = $textTokens * (float) $prices['input']
            + $imageTokens * (float) ($prices['input_image'] ?? 0)
            + $completionTokens * (float) $prices['output'];

        return round($cost / 1_000_000, AiMoney::PRECISION);
    }
}
