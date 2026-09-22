<?php

namespace CharlesStOlive\FilamentPrism\Filament\Widgets;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Historique des appels IA d'un « trackable » donné (par ex. un voyage), pour
 * brancher plus tard « tokens de ce voyage » sur sa propre page :
 *
 *     AiTokenUsageByTrackable::make(['trackable' => $voyage])
 */
class AiTokenUsageByTrackable extends TableWidget
{
    public ?Model $trackable = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Consommation IA')
            ->query(function (): Builder {
                return AiInteraction::query()
                    ->when(
                        $this->trackable,
                        fn (Builder $query) => $query
                            ->where('trackable_type', $this->trackable->getMorphClass())
                            ->where('trackable_id', $this->trackable->getKey()),
                    )
                    ->latest('id');
            })
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                TextColumn::make('task')->label('Tâche'),
                TextColumn::make('model')->label('Modèle'),
                TextColumn::make('total_tokens')->label('Tokens')->numeric()->sortable(),
                TextColumn::make('status')->label('Statut')->badge(),
            ]);
    }
}
