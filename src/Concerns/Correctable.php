<?php

namespace CharlesStOlive\FilamentPrism\Concerns;

use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use Illuminate\Database\Eloquent\Model;

/**
 * Pose sur un modèle Eloquent ses champs corrigibles par l'IA, et en fait un
 * `CorrectionSubject` à lui seul (`model()` renvoie `$this`, `key()` reste
 * `null` : le modèle seul suffit à identifier le sujet). Remplace
 * `HasTextExtraction` (filbreeze) : pas de chemins à points ni de fusion
 * récursive à la main, un champ direct du modèle par entrée. Si un futur
 * modèle a besoin de champs imbriqués/répétés, `CorrectableField` s'enrichira
 * à ce moment-là plutôt que d'être prévu ici sans usage.
 *
 * La classe qui pose ce trait doit aussi déclarer `implements
 * CorrectionSubject` (même principe que `HasMedia`/`InteractsWithMedia` de
 * Spatie) : un trait seul ne peut pas le faire à sa place.
 */
trait Correctable
{
    /** @return array<int, CorrectableField> */
    abstract public static function correctableFields(): array;

    public function model(): Model
    {
        /** @var Model $this */
        return $this;
    }

    public function key(): ?string
    {
        return null;
    }

    /** @return array<int, CorrectableField> */
    public function fields(): array
    {
        return static::correctableFields();
    }

    /** @return array<string, mixed> */
    public function extractValues(): array
    {
        return $this->only(static::correctableFieldNames());
    }

    /**
     * Écrit le résultat dans le modèle, sans le persister : à
     * `CorrectionService::apply()` d'appeler `save()` ensuite, une fois la
     * correction jugée valable.
     *
     * @param  array<string, mixed>  $values
     */
    public function applyValues(array $values): void
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
