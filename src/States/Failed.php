<?php

namespace CharlesStOlive\FilamentPrism\States;

class Failed extends AiInteractionStatus
{
    public static $name = 'failed';

    public function getLabel(): string
    {
        return 'Échec';
    }

    public function getColor(): string
    {
        return 'danger';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-exclamation-triangle';
    }

    public function getDescription(): ?string
    {
        return 'L’appel à l’IA a échoué.';
    }
}
