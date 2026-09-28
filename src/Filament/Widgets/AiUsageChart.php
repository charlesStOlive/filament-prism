<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Support\RawJs;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Les demandes au fil du temps, une série par ressource IA empilée : par jour
 * sur une période courte, par mois au-delà de trois mois. Les chiffres exacts
 * sont dans le tableau voisin (`AiUsageByResource`).
 *
 * Chaque ressource garde sa couleur quel que soit le filtre : elle suit son
 * rang dans la config (`filament-prism.tasks`), pas son rang dans le
 * graphique. Palette catégorielle à huit teintes, vérifiée pour les
 * daltonismes, avec ses propres teintes en mode sombre (lues au dessin, le
 * graphique se redessine quand le thème change) ; au-delà de huit ressources,
 * les couleurs reviennent.
 */
class AiUsageChart extends ChartWidget
{
    use InteractsWithPageFilters;

    private const LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    private const DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];

    protected ?string $heading = 'Demandes dans le temps';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        // Les jours du graphique sont ceux de l'utilisateur (son fuseau), pas ceux d'UTC.
        $timezone = FilamentTimezone::get();
        $interactions = AiUsage::query($this->pageFilters)->get(['task', 'created_at']);
        $since = Carbon::parse(AiUsage::since($this->pageFilters) ?? $interactions->min('created_at') ?? now())->setTimezone($timezone)->startOfDay();
        $monthly = $since->diffInDays(now()) > 92;
        $format = $monthly ? 'Y-m' : 'Y-m-d';

        $buckets = collect();
        for ($date = $monthly ? $since->copy()->startOfMonth() : $since->copy(); $date <= now($timezone); $monthly ? $date->addMonth() : $date->addDay()) {
            $buckets->push($date->format($format));
        }

        $labels = AiInteractionResource::taskLabels();
        $slots = array_flip(array_keys($labels));

        return [
            'labels' => $buckets
                ->map(fn (string $bucket): string => Carbon::createFromFormat('Y-m-d', $monthly ? $bucket.'-01' : $bucket)->translatedFormat($monthly ? 'M Y' : 'd/m'))
                ->all(),
            'datasets' => $interactions
                ->groupBy('task')
                ->sortBy(fn (Collection $items, string $task): int => $slots[$task] ?? PHP_INT_MAX)
                ->map(fn (Collection $items, string $task): array => [
                    'label' => $labels[$task] ?? $task,
                    'slot' => ($slots[$task] ?? count($slots)) % count(self::LIGHT),
                    'data' => $buckets
                        ->map(fn (string $bucket): int => $items->filter(fn (AiInteraction $interaction): bool => $interaction->created_at->setTimezone($timezone)->format($format) === $bucket)->count())
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        $light = json_encode(self::LIGHT);
        $dark = json_encode(self::DARK);

        return RawJs::make(<<<JS
            {
                datasets: {
                    bar: {
                        backgroundColor: (context) => (document.documentElement.classList.contains('dark') ? {$dark} : {$light})[context.dataset.slot ?? 0],
                        borderColor: 'transparent',
                        borderWidth: { top: 2 },
                        borderSkipped: 'bottom',
                        borderRadius: 4,
                        maxBarThickness: 32,
                    },
                },
                plugins: {
                    legend: { display: true, position: 'bottom' },
                    tooltip: { mode: 'index', intersect: false },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                },
            }
        JS);
    }
}
