<?php

namespace CharlesStOlive\FilamentPrism\States;

class Refined extends AiInteractionStatus
{
    public static $name = 'refined';

    public function getLabel(): string
    {
        return 'Affinée';
    }

    public function getColor(): string
    {
        return 'info';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-arrow-uturn-right';
    }

    public function getDescription(): ?string
    {
        return 'Une version plus récente, dans le même fil, la remplace.';
    }

    public function hasResult(): bool
    {
        return true;
    }
}
