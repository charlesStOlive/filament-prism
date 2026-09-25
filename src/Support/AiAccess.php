<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Qui voit quelles demandes IA : chacun les siennes — listes, fil d'une
 * demande, stats de consommation —, sauf qui peut tout voir (un super
 * utilisateur), qui voit alors aussi de qui est chaque demande.
 *
 * « Tout voir » se décide dans le panel (`FilamentPrismPlugin::seeAllRequestsUsing()`),
 * ou, sans lui, par l'ability `filament-prism.see-all-requests` (Gate) — refusée
 * tant que l'application ne la définit pas.
 */
final class AiAccess
{
    public const SEE_ALL_ABILITY = 'filament-prism.see-all-requests';

    public static function canSeeAll(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        if ($user === null) {
            return false;
        }

        try {
            $panel = filament()->getCurrentPanel();
        } catch (Throwable) {
            $panel = null;
        }

        if ($panel?->hasPlugin(FilamentPrismPlugin::ID)) {
            $decision = $panel->getPlugin(FilamentPrismPlugin::ID)->canSeeAllRequests($user);

            if ($decision !== null) {
                return $decision;
            }
        }

        return Gate::forUser($user)->allows(self::SEE_ALL_ABILITY);
    }

    /** Restreint une requête sur `ai_interactions` à ce que l'utilisateur courant peut voir. */
    public static function scope(Builder $query): Builder
    {
        return self::canSeeAll() ? $query : $query->where($query->qualifyColumn('user_id'), Auth::id());
    }
}
