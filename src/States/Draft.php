<?php

namespace CharlesStOlive\FilamentPrism\States;

class Draft extends AiInteractionStatus
{
    public static $name = 'draft';

    public function getLabel(): string
    {
        return 'Brouillon';
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-pencil-square';
    }

    public function getDescription(): ?string
    {
        return 'Rien n’a encore été envoyé à l’IA : on voit ce qui partira, puis on le soumet.';
    }
}
