<?php

namespace CharlesStOlive\FilamentPrism;

use CharlesStOlive\FilamentPrism\Filament\Pages\AiUsageStats;
use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Les pages de filament-prism dans un panel : « Demandes IA » (retrouver ses
 * demandes, leurs variantes, en refaire une) et « Consommation IA » (les
 * stats par ressource). Facultatif : sans lui, les ressources IA et leurs
 * actions marchent pareil — une notification de fin n'a juste nulle part où
 * mener, sauf si la ressource le dit (`AiResource::resultUrl()`).
 *
 *     ->plugins([FilamentPrismPlugin::make()->navigationGroup('Paramètres')])
 */
class FilamentPrismPlugin implements Plugin
{
    public const ID = 'filament-prism';

    protected ?string $navigationGroup = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(self::ID);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([AiInteractionResource::class])
            ->pages([AiUsageStats::class]);
    }

    public function boot(Panel $panel): void {}
}
