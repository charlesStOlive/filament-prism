<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Tokens dépensés par l'utilisateur courant, aujourd'hui et ce mois-ci.
 * Calculé sur `AiInteraction`, jamais sur un compteur séparé : la ligne
 * persistée dès l'appel IA est la seule source de vérité.
 */
class AiTokenUsageOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Consommation IA';

    protected function getStats(): array
    {
        $userId = Auth::id();

        $today = AiInteraction::query()
            ->where('user_id', $userId)
            ->whereDate('created_at', today())
            ->sum('total_tokens');

        $thisMonth = AiInteraction::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total_tokens');

        return [
            Stat::make('Tokens aujourd’hui', number_format($today, 0, ',', ' ')),
            Stat::make('Tokens ce mois', number_format($thisMonth, 0, ',', ' ')),
        ];
    }
}
