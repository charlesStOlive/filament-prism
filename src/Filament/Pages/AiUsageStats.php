<?php

namespace CharlesStOlive\FilamentPrism\Filament\Pages;

use CharlesStOlive\FilamentPrism\Filament\Widgets\AiUsageByResource;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiUsageChart;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * « Consommation IA » : par ressource IA, combien de demandes, combien
 * d'échecs, combien de résultats acceptés, ce qu'elles ont coûté et combien de
 * temps elles ont pris — sur une période, pour tout le monde ou pour une
 * personne. Tout est calculé sur `ai_interactions` (voir `Support\\AiUsage`), jamais
 * sur un compteur séparé.
 */
class AiUsageStats extends Page
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Consommation IA';

    protected static ?string $title = 'Consommation IA';

    protected static ?string $slug = 'ai-usage';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return filament()->hasPlugin(FilamentPrismPlugin::ID) ? FilamentPrismPlugin::get()->getNavigationGroup() : null;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Période')
                ->options(AiUsage::PERIODS)
                ->default('30')
                ->selectablePlaceholder(false),
            Select::make('user')
                ->label('Utilisateur')
                ->placeholder('Tout le monde')
                ->options(fn (): array => AiInteraction::query()
                    ->whereNotNull('user_id')
                    ->with('user')
                    ->get(['user_id'])
                    ->unique('user_id')
                    ->mapWithKeys(fn (AiInteraction $interaction): array => [$interaction->user_id => $interaction->user?->name ?? '#'.$interaction->user_id])
                    ->all()),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                AiUsageByResource::class,
                AiUsageChart::class,
            ])),
        ]);
    }
}
