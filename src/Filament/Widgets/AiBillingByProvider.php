<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Billing\AiBillingSync;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiMoney;
use CharlesStOlive\FilamentPrism\Support\AiUsage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Par fournisseur, sur la période : ce qu'il a réellement facturé (relevé par son API de
 * facturation, converti au taux BCE du jour), face à ce que l'application en a estimé — et leur
 * rapport, qui sert à recaler la part de chaque ressource et de chaque utilisateur. Une facture est
 * celle de tout le monde : pour qui peut tout voir seulement.
 */
class AiBillingByProvider extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AiAccess::canSeeAll();
    }

    public function table(Table $table): Table
    {
        $euros = fn (?float $amount): ?string => AiMoney::format($amount);
        $configured = app(AiBillingSync::class)->configuredProviders();

        return $table
            ->heading('Facturé par les fournisseurs')
            ->description($configured === []
                ? 'Aucune facture relevée : il faut une clé admin de facturation (ex. OPENAI_ADMIN_KEY), puis « Relever la facture ».'
                : 'Relevé par l’API de facturation de : '.implode(', ', $configured).'. Sans projet précisé (ex. OPENAI_PROJECT_ID), la facture couvre toute l’organisation, autres applications comprises.')
            ->records(fn (): array => AiUsage::billing($this->pageFilters)->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('provider')->label('Fournisseur')->weight('medium'),
                TextColumn::make('billed_eur')->label('Facturé')->formatStateUsing(fn (?float $state): ?string => $euros($state))->placeholder('—')->weight('medium'),
                TextColumn::make('billed')
                    ->label('Facturé (devise)')
                    ->formatStateUsing(fn (?float $state, array $record): ?string => AiMoney::format($state, $record['currency']))
                    ->placeholder('—'),
                TextColumn::make('estimated_eur')->label('Estimé par l’application')->formatStateUsing(fn (?float $state): ?string => $euros($state))->placeholder('—'),
                TextColumn::make('ratio')
                    ->label('Facturé / estimé')
                    ->tooltip('Au-dessus de 100 % : la facture couvre plus que les demandes de l’application (autres usages de la clé, prix du catalogue en retard...).')
                    ->formatStateUsing(fn (?float $state): ?string => $state === null ? null : number_format($state * 100, 0, ',', ' ').' %')
                    ->placeholder('—'),
                TextColumn::make('last_date')->label('Relevé jusqu’au')->date('d/m/Y')->placeholder('—'),
            ]);
    }
}
