<?php

namespace CharlesStOlive\FilamentPrism\Tasks;

/**
 * Une tâche IA enregistrée dans `config('filament-prism.tasks')` : sa clé
 * (utilisée pour retrouver/filtrer les `AiInteraction`), son prompt système,
 * son provider/modèle par défaut, et le renderer qui sait afficher sa réponse.
 */
interface AiTask
{
    public function key(): string;

    public function systemPrompt(): string;

    public function provider(): string;

    public function model(): string;

    /** @return class-string<\CharlesStOlive\FilamentPrism\Support\AiResultRenderer> */
    public function rendererClass(): string;
}
