<?php

namespace CharlesStOlive\FilamentPrism\Tasks;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\GroupedTextDiffRenderer;
use CharlesStOlive\FilamentPrism\Support\WordDiff;
use Filament\Schemas\Components\Html;
use Illuminate\Database\Eloquent\Model;
use Prism\Prism\Schema\ObjectSchema;

/**
 * Ressource par défaut : corrige l'orthographe et la grammaire des champs
 * déclarés `correctable` sur un modèle, sans changer le sens ni le ton.
 *
 * Pas de formulaire d'entrée : le texte vient du `CorrectionSubject` passé
 * par `CorrectionService`, avec ses champs, gardés dans la demande
 * (`meta.fields`, `meta.grouped` pour plusieurs sujets en un appel) pour
 * qu'un brouillon se soumette, ou une correction s'affine, de n'importe où
 * (`interactionContext()`). La réception est la revue de correction
 * (`CorrectionReview`), pas un formulaire : ni `receptionSchema()` ni `apply()`.
 *
 * Un brouillon montre les textes qui partiront ; « Accepter » depuis une carte
 * n'est proposé que quand la correction sait s'écrire seule (un modèle
 * `Correctable`) — un texte hors modèle s'applique depuis sa revue.
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
        return [Html::make(fn () => $this->isGrouped($interaction)
            ? app(GroupedTextDiffRenderer::class)->render($interaction, collect($interaction->input['items'] ?? [])->filter(fn ($item): bool => isset($item['key']))->keyBy('key')->all())->render()
            : app($this->rendererClass())->render($interaction)->render())];
    }

    /** Un brouillon : les textes qui partiront. Ensuite, le diff les montre déjà. */
    public function inputDisplaySchema(AiInteraction $interaction): array
    {
        if (! $interaction->isDraft()) {
            return [];
        }

        return [Html::make(fn () => view('filament-prism::livewire.partials.correction-draft', [
            'subjects' => self::draftSubjects($interaction),
        ])->render())];
    }

    /**
     * Les textes d'un brouillon, par sujet (un seul hors groupe), leurs champs dans l'ordre déclaré.
     *
     * @return array<int, array{key: string|null, fields: array<int, array{label: string, text: string}>}>
     */
    public static function draftSubjects(AiInteraction $interaction): array
    {
        $fields = CorrectableField::fromMeta((array) ($interaction->meta['fields'] ?? []));
        $labels = (array) ($interaction->meta['labels'] ?? []);
        $items = isset($interaction->input['items']) ? (array) $interaction->input['items'] : [$interaction->input ?? []];
        $names = $fields === [] ? null : array_map(fn (CorrectableField $field): string => $field->name, $fields);

        return collect($items)
            ->map(fn (array $item): array => [
                'key' => isset($item['key']) ? (string) $item['key'] : null,
                'fields' => collect($names ?? array_keys(array_diff_key($item, ['key' => true])))
                    ->map(fn (string $name): array => ['label' => (string) ($labels[$name] ?? $name), 'text' => WordDiff::textOf($item[$name] ?? '')])
                    ->filter(fn (array $field): bool => $field['text'] !== '')
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array{fields: array<int, CorrectableField>, grouped: bool} */
    public function interactionContext(AiInteraction $interaction): array
    {
        return ['fields' => CorrectableField::fromMeta((array) ($interaction->meta['fields'] ?? [])), 'grouped' => $this->isGrouped($interaction)];
    }

    /** Seulement quand la correction s'écrit seule dans son modèle (`Correctable`) ; sinon, depuis sa revue. */
    public function canAccept(AiInteraction $interaction): bool
    {
        $correctable = $interaction->correctable;

        return ! $this->isGrouped($interaction)
            && $correctable instanceof Model
            && in_array(Correctable::class, class_uses_recursive($correctable), true);
    }

    public function acceptLabel(): string
    {
        return 'Appliquer';
    }

    public function accept(AiInteraction $interaction, array $data = []): ?string
    {
        app(CorrectionService::class)->apply($interaction);

        return 'Correction appliquée';
    }

    private function isGrouped(AiInteraction $interaction): bool
    {
        // Les demandes d'avant `meta.grouped` se reconnaissent à leur liste de sujets.
        return (bool) ($interaction->meta['grouped'] ?? isset($interaction->input['items']));
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
