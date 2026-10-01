<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Qui peut lancer une tâche IA depuis une page (la correction orthographique d'un devis, d'un voyage…) : une ability Gate
 * `{classe de la Resource de la page}.ai.{tâche}`, quand l'application la définit — avec filament-permission-manager,
 * la permission `{liste}.ai.{tâche}`, que la Resource déclare par :
 *
 *     protected static array $permissionFamilies = ['ai' => 'Intelligence artificielle'];
 *
 *     public static function permissionActions(): array
 *     {
 *         return AiPermissions::actions(['orthography']);
 *     }
 *
 * Sans elle (ou hors d'une page de Resource), la tâche reste ouverte à qui voit la page : aucune dépendance à un
 * gestionnaire de permissions. Ce qu'on voit des demandes déjà faites se décide ailleurs (voir `AiAccess`).
 */
final class AiPermissions
{
    public const FAMILY = 'ai';

    /**
     * Les actions à déclarer pour des tâches : `ai.{tâche}` => libellé.
     *
     * @param  array<int, string>  $taskKeys
     * @return array<string, string>
     */
    public static function actions(array $taskKeys): array
    {
        $registry = app(AiTaskRegistry::class);

        return collect($taskKeys)
            ->mapWithKeys(fn (string $key): array => [
                self::FAMILY.'.'.$key => $registry->has($key) ? $registry->resource($key)->label() : $key,
            ])
            ->all();
    }

    /** `$livewire` : la page où l'action s'affiche ; sa Resource et son enregistrement, s'il y en a. */
    public static function allows(object $livewire, string $taskKey): bool
    {
        if (! method_exists($livewire, 'getResource')) {
            return true;
        }

        $ability = $livewire::getResource().'.'.self::FAMILY.'.'.$taskKey;
        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return ! Gate::has($ability) || Gate::allows($ability, $record instanceof Model ? [$record] : []);
    }
}
