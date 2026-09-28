<?php

namespace CharlesStOlive\FilamentPrism\States;

class Running extends AiInteractionStatus
{
    public static $name = 'running';

    public function getLabel(): string
    {
        return 'En cours';
    }

    public function getColor(): string
    {
        return 'info';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-arrow-path';
    }

    public function getDescription(): ?string
    {
        return 'L’IA travaille sur la demande.';
    }

    public function isActive(): bool
    {
        return true;
    }
}
