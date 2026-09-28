<?php

namespace CharlesStOlive\FilamentPrism\States;

class Queued extends AiInteractionStatus
{
    public static $name = 'queued';

    public function getLabel(): string
    {
        return 'En file';
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-clock';
    }

    public function getDescription(): ?string
    {
        return 'La demande attend son tour.';
    }

    public function isActive(): bool
    {
        return true;
    }
}
