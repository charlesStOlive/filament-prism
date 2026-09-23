<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Illuminate\Support\Str;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

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

    /**
     * Le schéma Prism partagé par tout `CorrectionSubject`, quelle que soit
     * la provenance des champs (colonnes d'un modèle `Correctable`, ou
     * champs d'un `FieldsCorrectionSubject`) : une `StringSchema` par champ.
     *
     * @param  array<int, CorrectableField>  $fields
     */
    public static function toObjectSchema(array $fields): ObjectSchema
    {
        $fields = collect($fields);

        return new ObjectSchema(
            name: 'correction',
            description: 'Version corrigée de chaque champ demandé.',
            properties: $fields
                ->map(fn (self $field): StringSchema => new StringSchema(
                    name: $field->name,
                    description: "Version corrigée de « {$field->getLabel()} ».",
                ))
                ->all(),
            requiredFields: $fields->map(fn (self $field): string => $field->name)->all(),
        );
    }

    /**
     * Le même schéma, répété pour plusieurs sujets corrigés en un seul appel
     * (voir `CorrectionSubjectGroup`/`CorrectionService::correctGroup()`) :
     * un tableau d'objets, chacun portant sa `key` — l'identifiant qui
     * permet de rattacher chaque correction reçue au bon sujet, l'IA ne
     * garantissant ni l'ordre ni la présence de chaque élément.
     *
     * @param  array<int, CorrectableField>  $fields
     */
    public static function toGroupedObjectSchema(array $fields): ObjectSchema
    {
        $fields = collect($fields);

        $itemProperties = [
            new StringSchema(name: 'key', description: 'L’identifiant du sujet, recopié tel quel depuis la question.'),
            ...$fields
                ->map(fn (self $field): StringSchema => new StringSchema(
                    name: $field->name,
                    description: "Version corrigée de « {$field->getLabel()} ».",
                ))
                ->all(),
        ];

        return new ObjectSchema(
            name: 'correction',
            description: 'Version corrigée de chaque sujet demandé.',
            properties: [
                new ArraySchema(
                    name: 'items',
                    description: 'Un élément par sujet reçu, dans n’importe quel ordre.',
                    items: new ObjectSchema(
                        name: 'item',
                        description: 'La correction d’un sujet.',
                        properties: $itemProperties,
                        requiredFields: ['key', ...$fields->map(fn (self $field): string => $field->name)->all()],
                    ),
                ),
            ],
            requiredFields: ['items'],
        );
    }
}
