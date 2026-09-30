<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiMoney;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Les tokens et le coût de chacun sur la période choisie (voir `AiUsage::byUser()`) — pour qui voit
 * les demandes d'autres personnes (tout le monde, ou un groupe : voir `AiAccess`) ; les autres n'ont
 * que leurs propres chiffres. La part de la facture reste à qui peut tout voir.
 */
class AiUsageByUser extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AiAccess::canSeeOthers();
    }

    public function table(Table $table): Table
    {
        $euros = fn (?float $amount): ?string => AiMoney::format($amount);

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
                TextColumn::make('cost_eur')
                    ->label('Coût estimé')
                    ->formatStateUsing(fn (?float $state, array $record): ?string => $state === null ? null : $euros($state).($record['cost_is_partial'] ? ' (partiel)' : ''))
                    ->placeholder('—'),
                TextColumn::make('billed_eur')
                    ->label('Part de la facture')
                    ->visible(fn (): bool => AiAccess::canSeeAll())
                    ->tooltip('L’estimation recalée sur ce que le fournisseur a réellement facturé sur la période.')
                    ->formatStateUsing(fn (?float $state): ?string => $euros($state))
                    ->placeholder('—'),
            ]);
    }
}
