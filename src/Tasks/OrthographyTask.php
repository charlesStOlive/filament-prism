<?php

namespace CharlesStOlive\FilamentPrism\Tasks;

use CharlesStOlive\FilamentPrism\Support\TextDiffRenderer;

/**
 * Tâche par défaut : corrige l'orthographe et la grammaire des champs
 * déclarés `correctable` sur un modèle, sans changer le sens ni le ton.
 */
class OrthographyTask implements AiTask
{
    public function key(): string
    {
        return 'orthography';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
            Tu es un correcteur orthographique et grammatical pour du contenu en français.

            On te donne un objet JSON dont chaque clé est un champ de texte à corriger —
            ou, quand plusieurs sujets sont corrigés en même temps, un tableau "items" dont
            chaque élément est un de ces objets, avec en plus une clé "key" à recopier telle
            quelle (elle identifie le sujet, ce n'est pas du texte à corriger). Dans les deux
            cas, pour chaque champ de texte, renvoie uniquement sa version corrigée
            (orthographe, grammaire, accords, ponctuation), dans la même clé.

            Ne reformule pas, ne raccourcis pas, ne change ni le sens ni le ton. Si un
            champ contient du HTML, conserve exactement les mêmes balises et ne corrige
            que le texte qu'elles entourent. Si un champ est déjà correct, renvoie-le
            à l'identique.
            PROMPT;
    }

    public function provider(): string
    {
        return (string) config('filament-prism.provider');
    }

    public function model(): string
    {
        return (string) config('filament-prism.model');
    }

    public function rendererClass(): string
    {
        return TextDiffRenderer::class;
    }
}
