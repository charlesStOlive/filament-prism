<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Support\AiMoney;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Ce que l'utilisateur courant a consommé lui-même — tokens aujourd'hui, ce
 * mois-ci, et le coût estimé du mois en euros quand ses modèles ont un prix
 * (voir `filament-prism.providers`). Calculé sur `AiInteraction`, jamais sur un
 * compteur séparé : la ligne persistée à chaque appel est la seule source de vérité.
 */
class AiTokenUsageOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Ma consommation';

    protected function getStats(): array
    {
        $mine = fn () => AiInteraction::query()->where('user_id', Auth::id());
        // Le jour et le mois de l'utilisateur (son fuseau), en bornes UTC pour interroger la base.
        $now = now(FilamentTimezone::get());
        $utc = (string) config('app.timezone');
        $month = [$now->copy()->startOfMonth()->setTimezone($utc), $now->copy()->endOfMonth()->setTimezone($utc)];

        $today = $mine()->whereBetween('created_at', [$now->copy()->startOfDay()->setTimezone($utc), $now->copy()->endOfDay()->setTimezone($utc)])->sum('total_tokens');
        $thisMonth = $mine()->whereBetween('created_at', $month)->sum('total_tokens');
        $cost = $mine()->whereBetween('created_at', $month)->whereNotNull('cost_eur')->sum('cost_eur');

        return [
            Stat::make('Tokens aujourd’hui', number_format((int) $today, 0, ',', ' ')),
            Stat::make('Tokens ce mois', number_format((int) $thisMonth, 0, ',', ' ')),
            Stat::make('Coût estimé ce mois', $cost > 0 ? AiMoney::format((float) $cost) : '—'),
        ];
    }
}
