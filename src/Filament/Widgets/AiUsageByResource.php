<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Les chiffres de chaque ressource IA sur la période choisie (voir `AiUsage::byResource()`).
 */
class AiUsageByResource extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $percent = fn (?float $rate): ?string => $rate === null ? null : number_format($rate * 100, 0, ',', ' ').' %';
        $euros = fn (?float $amount): ?string => $amount === null ? null : number_format($amount, 2, ',', ' ').' €';

        return $table
            ->heading('Par ressource IA')
            ->records(fn (): array => AiUsage::byResource($this->pageFilters)->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label('Ressource')->weight('medium'),
                TextColumn::make('requests')->label('Demandes')->numeric(),
                TextColumn::make('failed')
                    ->label('Échecs')
                    ->formatStateUsing(fn (int $state, array $record): string => $state.($state > 0 ? ' ('.$percent($record['failure_rate']).')' : ''))
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('acceptance_rate')
                    ->label('Acceptées')
                    ->tooltip('Parmi les résultats vérifiés : acceptés / (acceptés + ignorés)')
                    ->formatStateUsing(fn (?float $state): ?string => $percent($state))
                    ->placeholder('—'),
                TextColumn::make('prompt_tokens')->label('Tokens envoyés')->numeric(),
                TextColumn::make('completion_tokens')->label('Tokens reçus')->numeric(),
                TextColumn::make('cost_eur')
                    ->label('Coût estimé')
                    ->formatStateUsing(fn (?float $state, array $record): ?string => $state === null ? null : $euros($state).($record['cost_is_partial'] ? ' (partiel)' : ''))
                    ->tooltip(fn (array $record): ?string => $record['cost_is_partial'] ? 'Des demandes utilisent un modèle sans prix dans le catalogue (filament-prism.providers).' : 'Tokens × prix du catalogue, au taux BCE du jour de l’appel.')
                    ->placeholder('—'),
                TextColumn::make('billed_eur')
                    ->label('Part de la facture')
                    ->tooltip('L’estimation recalée sur ce que le fournisseur a réellement facturé sur la période.')
                    ->formatStateUsing(fn (?float $state): ?string => $euros($state))
                    ->placeholder('—'),
                TextColumn::make('average_duration')
                    ->label('Durée moyenne')
                    ->formatStateUsing(fn (?float $state): ?string => $state === null ? null : number_format($state, 1, ',', ' ').' s')
                    ->placeholder('—'),
            ]);
    }
}
