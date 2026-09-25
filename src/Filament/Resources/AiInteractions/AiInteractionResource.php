<?php

namespace CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages\ListAiInteractions;
use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages\ViewAiInteraction;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * « Demandes IA » : toutes les demandes faites à l'IA, pour retrouver celles
 * d'hier (une « session » passée) — et, depuis l'une d'elles, son fil : la
 * demande et ses variantes (voir `ViewAiInteraction`, `AiInteractionList`).
 * On n'y crée rien : une demande part de là où elle a un sens (la
 * bibliothèque, un formulaire...).
 *
 * Chacun n'y voit que ses demandes ; qui peut tout voir (voir `AiAccess`) voit
 * celles de tout le monde, avec leur auteur, et peut filtrer par personne.
 *
 * `$specificPermissions` déclare la permission `aiinteraction.viewallusers`
 * au format de charlesstolive/filament-permission-manager (`permissions:sync`
 * la crée) — sans en dépendre : une application qui s'en sert la vérifie dans
 * `FilamentPrismPlugin::seeAllRequestsUsing()`.
 */
class AiInteractionResource extends Resource
{
    protected static ?string $model = AiInteraction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Demandes IA';

    protected static ?string $modelLabel = 'demande IA';

    protected static ?string $pluralModelLabel = 'demandes IA';

    protected static ?string $slug = 'ai-interactions';

    /** Voir les demandes de tout le monde (voir la docblock de la classe). */
    public const SEE_ALL_PERMISSION = 'aiinteraction.viewallusers';

    /** @var array<int, string> Lue par filament-permission-manager (`permissions:sync`). */
    protected static array $specificPermissions = ['viewallusers'];

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return filament()->hasPlugin(FilamentPrismPlugin::ID) ? FilamentPrismPlugin::get()->getNavigationGroup() : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Chacun ses demandes, sauf qui peut tout voir — la page d'une demande comprise. */
    public static function getEloquentQuery(): Builder
    {
        return AiAccess::scope(parent::getEloquentQuery());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('task')->label('Ressource')->formatStateUsing(fn (string $state): string => static::taskLabels()[$state] ?? $state),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiInteraction::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => AiInteraction::statusColor($state)),
                TextColumn::make('user.name')->label('Par')->visible(fn (): bool => AiAccess::canSeeAll()),
                TextColumn::make('total_tokens')->label('Tokens')->numeric()->sortable(),
                TextColumn::make('model')->label('Modèle')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_eur')
                    ->label('Coût estimé')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : number_format((float) $state, 3, ',', ' ').' €')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Durée')
                    ->state(fn (AiInteraction $record): ?string => $record->isActive() || $record->durationInSeconds() === null ? null : $record->durationInSeconds().' s')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Par')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple()
                    ->visible(fn (): bool => AiAccess::canSeeAll()),
                SelectFilter::make('task')->label('Ressource')->options(fn (): array => static::taskLabels())->multiple(),
                SelectFilter::make('status')->label('Statut')->options(AiInteraction::statusLabels())->multiple(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Depuis le'),
                        DatePicker::make('until')->label('Jusqu’au'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()->label('Voir')])
            ->recordUrl(fn (AiInteraction $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiInteractions::route('/'),
            'view' => ViewAiInteraction::route('/{record}'),
        ];
    }

    /** @return array<string, string> clé => libellé des ressources enregistrées */
    public static function taskLabels(): array
    {
        return app(AiTaskRegistry::class)->all()
            ->map(fn (AiTask $task): string => method_exists($task, 'label') ? $task->label() : $task->key())
            ->all();
    }
}
