<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Plusieurs `CorrectionSubject` corrigés en **un seul appel IA** — le cas
 * d'école : tout un voyage (ses périodes) plutôt qu'une à la fois. Tous les
 * sujets doivent partager les mêmes champs (même `fields()`) : c'est ce qui
 * permet un unique schéma Prism (`CorrectableField::toGroupedObjectSchema()`),
 * répété pour chaque sujet plutôt qu'un schéma par sujet.
 *
 *     CorrectionSubjectGroup::make(
 *         model: $this->record,
 *         key: 'all-days',
 *         subjects: collect($this->data['days'])->mapWithKeys(fn (array $day): array => [
 *             $day['slug'] => FieldsCorrectionSubject::make(
 *                 model: $this->record,
 *                 key: 'day:'.$day['slug'],
 *                 fields: [CorrectableField::make('title'), CorrectableField::make('body')->html()],
 *                 get: fn (): array => Arr::only($day, ['title', 'body']),
 *             ),
 *         ])->all(),
 *     )
 */
class CorrectionSubjectGroup
{
    /** @param array<string, CorrectionSubject> $subjects */
    private function __construct(
        private readonly Model $model,
        private readonly string $key,
        private readonly array $subjects,
    ) {}

    /** @param array<string, CorrectionSubject> $subjects keyed by a stable id (ex. le slug d'une période) */
    public static function make(Model $model, string $key, array $subjects): self
    {
        if ($subjects === []) {
            throw new InvalidArgumentException('CorrectionSubjectGroup::make() a besoin d’au moins un sujet.');
        }

        return new self($model, $key, $subjects);
    }

    public function model(): Model
    {
        return $this->model;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    /** @return array<string, CorrectionSubject> */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /** Les champs communs à tous les sujets — le premier fait foi, ils sont censés être identiques. */
    public function fields(): array
    {
        return array_values($this->subjects)[0]->fields();
    }
}
