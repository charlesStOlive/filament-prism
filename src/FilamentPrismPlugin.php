<?php

namespace CharlesStOlive\FilamentPrism;

use CharlesStOlive\FilamentPrism\Enums\AiInteractionsDisplay;
use CharlesStOlive\FilamentPrism\Filament\Pages\AiUsageStats;
use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Les pages de filament-prism dans un panel : « Demandes IA » (retrouver ses
 * demandes, leurs variantes, en refaire une) et « Consommation IA » (les
 * stats par ressource). Facultatif : sans lui, les ressources IA et leurs
 * actions marchent pareil — une notification de fin n'a juste nulle part où
 * mener, sauf si la ressource le dit (`AiResource::resultUrl()`).
 *
 *     ->plugins([FilamentPrismPlugin::make()->navigationGroup('Paramètres')])
 *
 * Chacun n'y voit que ses demandes et sa consommation ; qui peut tout voir (un
 * super utilisateur) voit celles de tout le monde, avec leur auteur :
 *
 *     FilamentPrismPlugin::make()->seeAllRequestsUsing(fn (User $user): bool => $user->hasRole('Super Admin'))
 *
 * Sans cette règle, c'est l'ability `filament-prism.see-all-requests` qui
 * décide (voir `Support\AiAccess`).
 *
 * Où s'ouvrent les demandes IA d'un modèle, par défaut (chaque
 * `AiInteractionsAction` peut le changer) :
 *
 *     FilamentPrismPlugin::make()->interactionsDisplay(AiInteractionsDisplay::SlideOver)
 */
class FilamentPrismPlugin implements Plugin
{
    public const ID = 'filament-prism';

    protected ?string $navigationGroup = null;

    protected ?Closure $seeAllRequestsUsing = null;

    protected AiInteractionsDisplay $interactionsDisplay = AiInteractionsDisplay::Modal;

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

    /** @param  Closure(Authenticatable): bool  $callback */
    public function seeAllRequestsUsing(?Closure $callback): static
    {
        $this->seeAllRequestsUsing = $callback;

        return $this;
    }

    /** `null` : le panel ne le dit pas, l'ability décide (voir `Support\AiAccess`). */
    public function canSeeAllRequests(Authenticatable $user): ?bool
    {
        return $this->seeAllRequestsUsing === null ? null : (bool) ($this->seeAllRequestsUsing)($user);
    }

    public function interactionsDisplay(AiInteractionsDisplay $display): static
    {
        $this->interactionsDisplay = $display;

        return $this;
    }

    public function getInteractionsDisplay(): AiInteractionsDisplay
    {
        return $this->interactionsDisplay;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([AiInteractionResource::class])
            ->pages([AiUsageStats::class]);
    }

    public function boot(Panel $panel): void {}
}
