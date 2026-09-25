<?php

namespace CharlesStOlive\FilamentPrism\Filament\Pages;

use CharlesStOlive\FilamentPrism\Billing\AiBillingSync;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiBillingByProvider;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiTokenUsageOverview;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiUsageByModel;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiUsageByResource;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiUsageByUser;
use CharlesStOlive\FilamentPrism\Filament\Widgets\AiUsageChart;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * « Consommation IA » : par ressource IA, combien de demandes, combien
 * d'échecs, combien de résultats acceptés, ce qu'elles ont coûté et combien de
 * temps elles ont pris — sur une période. Chacun y voit sa propre
 * consommation ; qui peut tout voir (voir `AiAccess`) celle de tout le monde,
 * par utilisateur, et peut filtrer sur une personne. Tout est calculé sur `ai_interactions` (voir `Support\\AiUsage`), jamais
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

    /** Relever tout de suite la facture des fournisseurs (sinon : chaque jour, par le scheduler). */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncBilling')
                ->label('Relever la facture')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => AiAccess::canSeeAll())
                ->action(function (): void {
                    $result = app(AiBillingSync::class)->sync(31);
                    $lines = collect($result['providers'])->map(fn (int|string $outcome, string $provider): string => $provider.' : '.(is_int($outcome) ? "{$outcome} ligne(s)" : $outcome));

                    Notification::make()
                        ->title($result['providers'] === [] ? 'Aucune facture à relever' : 'Facture relevée')
                        ->body($result['providers'] === []
                            ? 'Il faut une clé admin de facturation (ex. OPENAI_ADMIN_KEY). Les taux BCE ont été relevés.'
                            : $lines->implode("\n"))
                        ->status($result['providers'] === [] ? 'warning' : 'success')
                        ->send();
                }),
        ];
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
                ->visible(fn (): bool => AiAccess::canSeeAll())
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
                AiTokenUsageOverview::class,
                AiBillingByProvider::class,
                AiUsageByResource::class,
                AiUsageByUser::class,
                AiUsageByModel::class,
                AiUsageChart::class,
            ])),
        ]);
    }
}
