<?php

namespace CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages\ListAiInteractions;
use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages\ViewAiInteraction;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * « Demandes IA » : toutes les demandes faites à l'IA, pour retrouver celles
 * d'hier (une « session » passée) — et, depuis l'une d'elles, son fil : la
 * demande et ses variantes (voir `ViewAiInteraction`, `AiInteractionList`).
 * On n'y crée rien : une demande part de là où elle a un sens (la
 * bibliothèque, un formulaire...).
 */
class AiInteractionResource extends Resource
{
    protected static ?string $model = AiInteraction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Demandes IA';

    protected static ?string $modelLabel = 'demande IA';

    protected static ?string $pluralModelLabel = 'demandes IA';

    protected static ?string $slug = 'ai-interactions';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return filament()->hasPlugin(FilamentPrismPlugin::ID) ? FilamentPrismPlugin::get()->getNavigationGroup() : null;
    }

    public static function canCreate(): bool
    {
        return false;
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
                TextColumn::make('user.name')->label('Par'),
                TextColumn::make('total_tokens')->label('Tokens')->numeric()->sortable(),
                TextColumn::make('cost')
                    ->label('Coût')
                    ->numeric(3)
                    ->suffix(' '.config('filament-prism.currency'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Durée')
                    ->state(fn (AiInteraction $record): ?string => $record->isActive() || $record->durationInSeconds() === null ? null : $record->durationInSeconds().' s')
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('mine')
                    ->label('Mes demandes')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->where('user_id', Auth::id())),
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
