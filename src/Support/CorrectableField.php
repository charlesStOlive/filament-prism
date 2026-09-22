<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Illuminate\Support\Str;

/**
 * Un champ de texte qu'un modèle `Correctable` expose à la correction IA —
 * remplace les chemins à points (`$getTextes`) par une déclaration explicite,
 * à plat, par modèle :
 *
 *     CorrectableField::make('title'),
 *     CorrectableField::make('body')->html(),
 */
class CorrectableField
{
    protected ?string $labelOverride = null;

    protected bool $isHtml = false;

    final public function __construct(public readonly string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string $label): static
    {
        $this->labelOverride = $label;

        return $this;
    }

    public function html(bool $condition = true): static
    {
        $this->isHtml = $condition;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->labelOverride ?? Str::headline($this->name);
    }

    public function isHtml(): bool
    {
        return $this->isHtml;
    }
}
