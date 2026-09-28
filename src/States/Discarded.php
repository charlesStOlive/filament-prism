<?php

namespace CharlesStOlive\FilamentPrism\States;

class Discarded extends AiInteractionStatus
{
    public static $name = 'discarded';

    public function getLabel(): string
    {
        return 'Ignorée';
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-x-circle';
    }

    public function getDescription(): ?string
    {
        return 'Le résultat a été écarté.';
    }

    public function hasResult(): bool
    {
        return true;
    }
}
