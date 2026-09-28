<?php

namespace CharlesStOlive\FilamentPrism\States;

class Archived extends AiInteractionStatus
{
    public static $name = 'archived';

    public function getLabel(): string
    {
        return 'Archivée';
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-archive-box';
    }

    public function getDescription(): ?string
    {
        return 'Rangée : elle ne figure plus dans les demandes de son modèle.';
    }

    /** Celle d'une demande acceptée, ignorée ou affinée, pas celle d'un échec. */
    public function hasResult(): bool
    {
        return $this->getModel()?->archived_from !== Failed::getMorphClass();
    }
}
