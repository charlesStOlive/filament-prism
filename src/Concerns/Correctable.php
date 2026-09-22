<?php

namespace CharlesStOlive\FilamentPrism\Concerns;

use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Pose sur un modèle Eloquent ses champs corrigibles par l'IA. Remplace
 * `HasTextExtraction` (filbreeze) : pas de chemins à points ni de fusion
 * récursive à la main, un champ direct du modèle par entrée. Si un futur
 * modèle a besoin de champs imbriqués/répétés, `CorrectableField` s'enrichira
 * à ce moment-là plutôt que d'être prévu ici sans usage.
 */
trait Correctable
{
    /** @return array<int, CorrectableField> */
    abstract public static function correctableFields(): array;

    public static function prismObjectSchema(): ObjectSchema
    {
        $fields = collect(static::correctableFields());

        return new ObjectSchema(
            name: 'correction',
            description: 'Version corrigée de chaque champ demandé.',
            properties: $fields
                ->map(fn (CorrectableField $field): StringSchema => new StringSchema(
                    name: $field->name,
                    description: "Version corrigée de « {$field->getLabel()} ».",
                ))
                ->all(),
            requiredFields: $fields->map(fn (CorrectableField $field): string => $field->name)->all(),
        );
    }

    /** @return array<string, mixed> */
    public function extractCorrectableValues(): array
    {
        return $this->only(static::correctableFieldNames());
    }

    /** @param array<string, mixed> $values */
    public function applyCorrectableValues(array $values): void
    {
        $this->fill(array_intersect_key($values, array_flip(static::correctableFieldNames())));
    }

    /** @return array<int, string> */
    protected static function correctableFieldNames(): array
    {
        return collect(static::correctableFields())
            ->map(fn (CorrectableField $field): string => $field->name)
            ->all();
    }
}
