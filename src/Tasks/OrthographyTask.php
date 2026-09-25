<?php

namespace CharlesStOlive\FilamentPrism\Tasks;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use Filament\Schemas\Components\Html;
use Prism\Prism\Schema\ObjectSchema;

/**
 * Ressource par défaut : corrige l'orthographe et la grammaire des champs
 * déclarés `correctable` sur un modèle, sans changer le sens ni le ton.
 *
 * Pas de formulaire d'entrée : le texte vient du `CorrectionSubject` passé
 * par `CorrectionService`, avec ses champs dans le contexte
 * (`context['fields']`, et `context['grouped']` pour plusieurs sujets en un
 * appel). La réception est la revue de correction (`CorrectionReview`), pas
 * un formulaire : ni `receptionSchema()` ni `apply()`.
 */
class OrthographyTask extends AiResource
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

    public function label(): string
    {
        return 'Orthographe';
    }

    /** Dans « Demandes IA » aussi, le diff « Avant / Après » de la revue, plutôt qu'une grille des textes corrigés. */
    public function resultSchema(AiInteraction $interaction): array
    {
        return [Html::make(fn () => app($this->rendererClass())->render($interaction)->render())];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{fields: array<int, CorrectableField>, grouped?: bool}  $context
     */
    public function responseSchema(array $input, array $context): ObjectSchema
    {
        return ($context['grouped'] ?? false)
            ? CorrectableField::toGroupedObjectSchema($context['fields'])
            : CorrectableField::toObjectSchema($context['fields']);
    }

    /**
     * Force chaque champ à être une vraie chaîne (voir `CorrectableField::sanitizeValues()`) ; en
     * groupe, item par item, la clé repassée en chaîne — un item dont elle manque ou n'est pas
     * exploitable est rejeté, il ne pourrait de toute façon se rattacher à aucun sujet demandé.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @param  array{fields: array<int, CorrectableField>, grouped?: bool}  $context
     * @return array<string, mixed>
     */
    public function resolve(array $output, array $input, array $context): array
    {
        if (! ($context['grouped'] ?? false)) {
            return CorrectableField::sanitizeValues($context['fields'], $output);
        }

        return [
            'items' => collect($output['items'] ?? [])
                ->filter(fn ($item): bool => is_array($item) && is_scalar($item['key'] ?? null))
                ->map(fn (array $item): array => ['key' => (string) $item['key'], ...CorrectableField::sanitizeValues($context['fields'], $item)])
                ->values()
                ->all(),
        ];
    }
}
