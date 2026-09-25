<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiMoney;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Les modèles utilisés sur la période, fournisseur par fournisseur : leur type (texte, image...),
 * les demandes, les tokens, le coût estimé et — pour qui peut tout voir — ce que leur ligne de
 * facture a coûté. Un modèle sans prix dans le catalogue est signalé : son coût reste inconnu.
 */
class AiUsageByModel extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $euros = fn (?float $amount): ?string => AiMoney::format($amount);

        return $table
            ->heading('Par modèle')
            ->records(fn (): array => AiUsage::byModel($this->pageFilters)->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('provider')->label('Fournisseur'),
                TextColumn::make('model')->label('Modèle')->weight('medium')->fontFamily(FontFamily::Mono),
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'image' => 'Image',
                        'text' => 'Texte',
                        'structured' => 'Texte structuré',
                        null => '—',
                        default => $state,
                    })
                    ->color('gray'),
                TextColumn::make('requests')->label('Demandes')->numeric(),
                TextColumn::make('tokens')->label('Tokens')->numeric(),
                IconColumn::make('priced')->label('Prix connu')->boolean()
                    ->tooltip(fn (array $record): ?string => $record['priced'] ? null : 'Absent du catalogue filament-prism.providers : coût estimé inconnu.'),
                TextColumn::make('cost_eur')->label('Coût estimé')->formatStateUsing(fn (?float $state): ?string => $euros($state))->placeholder('—'),
                TextColumn::make('billed_eur')
                    ->label('Facturé')
                    ->formatStateUsing(fn (?float $state): ?string => $euros($state))
                    ->placeholder('—')
                    ->visible(fn (): bool => AiAccess::canSeeAll()),
            ]);
    }
}
