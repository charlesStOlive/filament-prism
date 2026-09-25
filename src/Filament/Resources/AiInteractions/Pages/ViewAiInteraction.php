<?php

namespace CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\Pages;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\Livewire\AiInteractionList;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Une demande et son fil : ses variantes (« Refaire » avec d'autres
 * réglages), les plus récentes d'abord, chacune avec son résultat et ce qu'on
 * peut en faire.
 */
class ViewAiInteraction extends ViewRecord
{
    protected static string $resource = AiInteractionResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var AiInteraction $record */
        $record = $this->getRecord();

        return AiInteractionResource::taskLabels()[$record->task] ?? $record->task;
    }

    public function content(Schema $schema): Schema
    {
        /** @var AiInteraction $record */
        $record = $this->getRecord();

        return $schema->components([AiInteractionList::forThread((string) $record->thread_id)]);
    }
}
