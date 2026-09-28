<?php

namespace CharlesStOlive\FilamentPrism\States;

class Accepted extends AiInteractionStatus
{
    public static $name = 'accepted';

    public function getLabel(): string
    {
        return 'Acceptée';
    }

    public function getColor(): string
    {
        return 'success';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-check-circle';
    }

    public function getDescription(): ?string
    {
        return 'Le résultat a été retenu.';
    }

    public function hasResult(): bool
    {
        return true;
    }
}
