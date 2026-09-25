<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Illuminate\Support\Str;

/**
 * Les fournisseurs d'IA et leurs modèles, tels que la config les décrit
 * (`filament-prism.providers`) : un libellé, la devise dans laquelle le
 * fournisseur facture, sa source de facturation (voir `Billing\`), et pour
 * chaque modèle son type (`text`, `image`...) et ses prix.
 *
 * Un modèle absent du catalogue marche pareil : il n'a juste ni prix (coût
 * inconnu, voir `AiCost`) ni type déclaré.
 */
final class AiProviders
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return (array) config('filament-prism.providers', []);
    }

    public static function label(string $provider): string
    {
        return (string) (self::all()[$provider]['label'] ?? Str::headline($provider));
    }

    /** La devise des prix et de la facture du fournisseur (ISO 4217). */
    public static function currency(string $provider): string
    {
        return strtoupper((string) (self::all()[$provider]['currency'] ?? config('filament-prism.currency', 'USD')));
    }

    /**
     * La fiche d'un modèle : `type`, et ses prix pour un million de tokens (`input`, `output`,
     * `input_image` pour un modèle d'image). Repli : l'ancienne table `filament-prism.pricing`, par modèle.
     *
     * @return array<string, mixed>|null
     */
    public static function model(string $provider, string $model): ?array
    {
        $entry = self::all()[$provider]['models'][$model] ?? config("filament-prism.pricing.{$model}");

        return is_array($entry) ? $entry : null;
    }

    public static function modelType(string $provider, string $model): ?string
    {
        return self::model($provider, $model)['type'] ?? null;
    }

    /** @return array<string, mixed> La configuration de facturation du fournisseur (`source`, clé admin...). */
    public static function billing(string $provider): array
    {
        return (array) (self::all()[$provider]['billing'] ?? []);
    }
}
