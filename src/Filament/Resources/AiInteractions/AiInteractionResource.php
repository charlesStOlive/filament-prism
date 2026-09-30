<?php

namespace CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages\ListAiInteractions;
use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages\ViewAiInteraction;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\States\Archived;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiMoney;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use CharlesStOlive\FilamentStateFusionEnhanced\Actions\StateFusionAction;
use CharlesStOlive\FilamentStateFusionEnhanced\Tables\Filters\StateFusionSelectFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * « Demandes IA » : toutes les demandes faites à l'IA, pour retrouver celles
 * d'hier (une « session » passée) — et, depuis l'une d'elles, son fil : la
 * demande et ses variantes (voir `ViewAiInteraction`, `AiInteractionList`).
 * On n'y crée rien : une demande part de là où elle a un sens (la
 * bibliothèque, un formulaire...). Les demandes archivées, qui ont quitté les
 * listes de leur modèle, restent ici (voir `AiInteractionStatus`).
 *
 * Chacun n'y voit que ses demandes ; qui peut tout voir (voir `AiAccess`) voit
 * celles de tout le monde, qui voit un groupe celles de ce groupe, avec leur
 * auteur, et peut filtrer par personne.
 *
 * Deux déclarations au format de charlesstolive/filament-permission-manager
 * (`permissions:sync` les crée), sans en dépendre :
 * - `$specificPermissions` : `aiinteraction.viewallusers`, qu'une application
 *   vérifie dans `FilamentPrismPlugin::seeAllRequestsUsing()` ;
 * - `$roleScopedPermissions` : `aiinteraction.viewrole.{rôle}`, une par rôle —
 *   voir les demandes des utilisateurs de ce rôle ; une application s'en sert
 *   dans `AiAccess::visibleUsersUsing()`.
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

    /** Voir les demandes des utilisateurs d'un rôle : `aiinteraction.viewrole.{rôle}` (voir la docblock de la classe). */
    public const VIEW_ROLE_PERMISSION = 'aiinteraction.viewrole';

    /** @var array<int, string> Lue par filament-permission-manager : une permission par rôle existant. */
    protected static array $roleScopedPermissions = ['viewrole'];

    /** @var array<string, string> Leurs libellés dans l'écran des rôles (filament-permission-manager). */
    protected static array $permissionLabels = [
        'viewallusers' => 'Voir les demandes et la consommation de tout le monde',
        'viewrole' => 'Voir les demandes et la consommation des utilisateurs du rôle',
    ];

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
                // Libellé, couleur et icône viennent de l'état (`AiInteractionStatus`).
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('user.name')->label('Par')->visible(fn (): bool => AiAccess::canSeeOthers()),
                TextColumn::make('total_tokens')->label('Tokens')->numeric()->sortable(),
                TextColumn::make('model')->label('Modèle')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_eur')
                    ->label('Coût estimé')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : AiMoney::format((float) $state))
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
                    ->relationship('user', 'name', fn (Builder $query): Builder => AiAccess::scopeUsers($query))
                    ->searchable()
                    ->preload()
                    ->multiple()
                    ->visible(fn (): bool => AiAccess::canSeeOthers()),
                SelectFilter::make('task')->label('Ressource')->options(fn (): array => static::taskLabels())->multiple(),
                StateFusionSelectFilter::make('status')->label('Statut')->multiple(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Depuis le'),
                        DatePicker::make('until')->label('Jusqu’au'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()->label('Voir'),
                // Libellé et icône viennent de la transition (`ToArchived`) ; cachée si la demande n'est pas terminée.
                StateFusionAction::make('archive')
                    ->attribute('status')
                    ->transitionTo(Archived::class)
                    ->requiresConfirmation(false)
                    ->iconButton(),
                Action::make('unarchive')
                    ->label('Désarchiver')
                    ->icon('heroicon-m-archive-box-x-mark')
                    ->color('gray')
                    ->iconButton()
                    ->visible(fn (AiInteraction $record): bool => $record->isArchived())
                    ->action(fn (AiInteraction $record) => $record->unarchive()),
            ])
            ->toolbarActions([
                BulkAction::make('archive')
                    ->label('Archiver')
                    ->icon('heroicon-m-archive-box-arrow-down')
                    ->color('gray')
                    ->deselectRecordsAfterCompletion()
                    // Seulement les demandes terminées : les autres restent où elles sont.
                    ->action(fn (Collection $records) => $records
                        ->filter(fn (AiInteraction $record): bool => $record->canBeArchived())
                        ->each(fn (AiInteraction $record) => $record->archive())),
            ])
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
