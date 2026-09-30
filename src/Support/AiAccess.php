<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Closure;
use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Qui voit quelles demandes IA : chacun les siennes — listes, fil d'une
 * demande, stats de consommation —, sauf :
 *
 * - qui peut **tout voir** (un super utilisateur) : les demandes de tout le monde, avec leur auteur, et ce que les
 *   fournisseurs ont facturé. Se décide dans le panel (`FilamentPrismPlugin::seeAllRequestsUsing()`), sinon par l'ability
 *   `{AiInteractionResource}.viewallusers` (définie par filament-permission-manager pour la permission
 *   `aiinteraction.viewallusers`), sinon par l'ability `filament-prism.see-all-requests` — refusée tant que
 *   l'application ne définit ni l'une ni l'autre ;
 * - qui voit **un groupe** : ses demandes et celles de certains utilisateurs, avec leur auteur, mais pas la facture.
 *   L'application dit qui, avec `AiAccess::visibleUsersUsing()` (prism ne sait pas ce qu'est un groupe : un rôle,
 *   un service…).
 */
final class AiAccess
{
    public const SEE_ALL_ABILITY = 'filament-prism.see-all-requests';

    /** @var (Closure(Authenticatable, Builder): ?Builder)|null */
    private static ?Closure $visibleUsersResolver = null;

    /**
     * Les autres utilisateurs dont une personne voit les demandes IA, sans tout voir. Le callback reçoit la personne et
     * une requête sur les utilisateurs, et la restreint à ceux qu'elle voit — ou renvoie `null` : personne d'autre.
     *
     *     AiAccess::visibleUsersUsing(fn (User $viewer, Builder $users): ?Builder => $users->role(['editeur']));
     *
     * À déclarer au démarrage de l'application (`AppServiceProvider::boot()`) : la règle vaut dans tous les panels,
     * avec ou sans le plugin. `null` la retire.
     */
    public static function visibleUsersUsing(?Closure $resolver): void
    {
        self::$visibleUsersResolver = $resolver;
    }

    /**
     * Les autres utilisateurs que `$user` voit, en plus de lui-même ; `null` : personne (ou pas de règle). Ne dit rien
     * de « tout voir » (voir `canSeeAll()`).
     */
    public static function visibleUsers(?Authenticatable $user = null): ?Builder
    {
        $user ??= Auth::user();

        if ($user === null || self::$visibleUsersResolver === null || ! method_exists($user, 'newQuery')) {
            return null;
        }

        return (self::$visibleUsersResolver)($user, $user->newQuery());
    }

    /** Vrai si `$user` voit les demandes d'autres personnes (tout le monde ou un groupe) : il voit alors leur auteur. */
    public static function canSeeOthers(?Authenticatable $user = null): bool
    {
        return self::canSeeAll($user) || self::visibleUsers($user) !== null;
    }

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

        // L'action propre déclarée par AiInteractionResource (`$specificPermissions`) : filament-permission-manager
        // définit l'ability `{Resource}.viewallusers` (permission `aiinteraction.viewallusers`), sans dépendance ici.
        $ability = AiInteractionResource::class.'.viewallusers';

        if (Gate::has($ability)) {
            return Gate::forUser($user)->allows($ability);
        }

        return Gate::forUser($user)->allows(self::SEE_ALL_ABILITY);
    }

    /** Restreint une requête sur `ai_interactions` à ce que l'utilisateur courant peut voir. */
    public static function scope(Builder $query): Builder
    {
        if (self::canSeeAll()) {
            return $query;
        }

        $column = $query->qualifyColumn('user_id');
        $others = self::visibleUsers();

        return $query->where(fn (Builder $query) => $query
            ->where($column, Auth::id())
            ->when($others, fn (Builder $query, Builder $others) => $query->orWhereIn($column, $others->select($others->getModel()->getQualifiedKeyName()))));
    }

    /** Restreint une requête sur les utilisateurs à ceux dont l'utilisateur courant voit les demandes, lui compris. */
    public static function scopeUsers(Builder $users): Builder
    {
        if (self::canSeeAll()) {
            return $users;
        }

        $key = $users->getModel()->getQualifiedKeyName();
        $others = self::visibleUsers();

        return $users->where(fn (Builder $query) => $query
            ->where($key, Auth::id())
            ->when($others, fn (Builder $query, Builder $others) => $query->orWhereIn($key, $others->select($others->getModel()->getQualifiedKeyName()))));
    }
}
