<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Les tokens et le coût de chacun sur la période choisie (voir `AiUsage::byUser()`) — pour qui
 * peut tout voir seulement : les autres n'ont que leurs propres chiffres.
 */
class AiUsageByUser extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AiAccess::canSeeAll();
    }

    public function table(Table $table): Table
    {
        $currency = ' '.config('filament-prism.currency');

        return $table
            ->heading('Par utilisateur')
            ->records(fn (): array => AiUsage::byUser($this->pageFilters)->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('user')->label('Utilisateur')->weight('medium'),
                TextColumn::make('requests')->label('Demandes')->numeric(),
                TextColumn::make('failed')->label('Échecs')->numeric()->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('prompt_tokens')->label('Tokens envoyés')->numeric(),
                TextColumn::make('completion_tokens')->label('Tokens reçus')->numeric(),
                TextColumn::make('total_tokens')->label('Tokens')->numeric()->weight('medium'),
                TextColumn::make('cost')
                    ->label('Coût')
                    ->formatStateUsing(fn (?float $state, array $record): ?string => $state === null ? null
                        : number_format($state, 2, ',', ' ').$currency.($record['cost_is_partial'] ? ' (partiel)' : ''))
                    ->placeholder('—'),
            ]);
    }
}
