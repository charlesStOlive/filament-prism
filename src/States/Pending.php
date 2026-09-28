<?php

namespace CharlesStOlive\FilamentPrism\States;

class Pending extends AiInteractionStatus
{
    public static $name = 'pending';

    public function getLabel(): string
    {
        return 'À vérifier';
    }

    public function getColor(): string
    {
        return 'warning';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-eye';
    }

    public function getDescription(): ?string
    {
        return 'Le résultat attend qu’on l’accepte ou qu’on l’ignore.';
    }

    public function hasResult(): bool
    {
        return true;
    }
}
