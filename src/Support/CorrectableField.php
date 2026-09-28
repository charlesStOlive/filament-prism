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
        return new ObjectSchema(
            name: 'correction',
            description: 'Version corrigée de chaque champ demandé.',
            properties: self::fieldSchemas($fields),
            requiredFields: self::names($fields),
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
                        properties: [
                            new StringSchema(name: 'key', description: 'L’identifiant du sujet, recopié tel quel depuis la question.'),
                            ...self::fieldSchemas($fields),
                        ],
                        requiredFields: ['key', ...self::names($fields)],
                    ),
                ),
            ],
            requiredFields: ['items'],
        );
    }

    /**
     * @param  array<int, CorrectableField>  $fields
     * @return array<int, StringSchema>
     */
    private static function fieldSchemas(array $fields): array
    {
        return array_values(array_map(fn (self $field): StringSchema => new StringSchema(
            name: $field->name,
            description: "Version corrigée de « {$field->getLabel()} ».",
        ), $fields));
    }

    /**
     * @param  array<int, CorrectableField>  $fields
     * @return array<int, string>
     */
    private static function names(array $fields): array
    {
        return array_values(array_map(fn (self $field): string => $field->name, $fields));
    }

    /**
     * Force chaque champ déclaré à être une vraie chaîne avant de persister
     * une réponse IA (`CorrectionService::correct()`/`correctGroup()`) : le
     * schéma Prism (`StringSchema`) déclare bien des chaînes, mais rien ne
     * garantit qu'un provider le respecte à la lettre (un tableau vide au
     * lieu d'une chaîne vide, par exemple). Sans ce filtre, une valeur
     * malformée finirait soit par planter l'affichage (`TextDiffRenderer`
     * caste en `string`), soit — pire — par écraser un champ réel d'un
     * tableau au clic sur « Appliquer » (voir `FieldsCorrectionSubject`,
     * où le résultat rejoint un état Livewire sans passer par un modèle qui
     * l'aurait retypé).
     *
     * Une valeur qui n'est pas un scalaire (donc pas convertible en chaîne
     * sans avertissement) devient une chaîne vide plutôt que de remonter
     * telle quelle — le champ apparaîtra « vidé » dans le diff, visible et
     * sans danger tant que l'utilisateur ne l'a pas explicitement appliqué.
     *
     * Pour un champ HTML, les séquences `\/` (et `\r\/`, `\n\/`) redeviennent `/` : c'est la trace
     * d'un JSON mal digéré par l'IA (voir `CorrectionService::callAi()`), jamais du texte voulu — et
     * `<\/p>` n'est pas une balise fermante pour un parseur HTML, qui laisserait alors l'élément
     * ouvert avaler la suite du texte.
     *
     * @param  array<int, CorrectableField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function sanitizeValues(array $fields, array $values): array
    {
        return collect($fields)
            ->mapWithKeys(function (self $field) use ($values): array {
                $value = $values[$field->name] ?? '';
                $value = is_scalar($value) ? (string) $value : '';

                if ($field->isHtml()) {
                    $value = preg_replace('#\\\\(?:[rn]\\\\)?/#', '/', $value) ?? $value;
                }

                return [$field->name => $value];
            })
            ->all();
    }

    /**
     * Le libellé de chaque champ, par nom — posé dans `AiInteraction.meta` à la persistance
     * (`CorrectionService::persist()`) : le renderer (`TextDiffRenderer`/`GroupedTextDiffRenderer`)
     * n'a accès qu'à l'`AiInteraction` déjà en base, jamais aux `CorrectableField` d'origine (le
     * `CorrectionSubject` ne survit pas à la requête qui a appelé l'IA) — sans ça, un `->label()`
     * personnalisé n'aurait jamais aucun effet sur l'affichage, silencieusement ignoré.
     *
     * @param  array<int, CorrectableField>  $fields
     * @return array<string, string>
     */
    public static function labelsByName(array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (self $field): array => [$field->name => $field->getLabel()])->all();
    }

    /**
     * Les champs, tels qu'une demande les garde (`meta.fields`) : un brouillon peut être soumis, ou
     * une correction affinée, dans une autre requête que celle où ils ont été déclarés.
     *
     * @param  array<int, CorrectableField>  $fields
     * @return array<int, array{name: string, label: string, html: bool}>
     */
    public static function toMeta(array $fields): array
    {
        return array_map(fn (self $field): array => ['name' => $field->name, 'label' => $field->getLabel(), 'html' => $field->isHtml()], array_values($fields));
    }

    /**
     * @param  array<int, array{name?: string, label?: string, html?: bool}>  $meta
     * @return array<int, CorrectableField>
     */
    public static function fromMeta(array $meta): array
    {
        return collect($meta)
            ->filter(fn (mixed $field): bool => is_array($field) && is_string($field['name'] ?? null))
            ->map(fn (array $field): self => static::make($field['name'])->label((string) ($field['label'] ?? Str::headline($field['name'])))->html((bool) ($field['html'] ?? false)))
            ->values()
            ->all();
    }
}
