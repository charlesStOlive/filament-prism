<?php

namespace CharlesStOlive\FilamentPrism\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Un `CorrectionSubject` pour un texte qui ne vit pas dans les colonnes d'un
 * modèle Eloquent — l'état d'un formulaire Livewire, par exemple. L'appel IA
 * se rattache tout de même à un modèle réel et stable (`$model`), pour durer
 * une coupure de session ; la lecture des valeurs actuelles passe par une
 * fonction explicite.
 *
 * Contrairement à `Correctable`, ce sujet ne sait pas écrire le résultat
 * corrigé quelque part : appliquer la correction est la responsabilité de
 * l'appelant (voir `CorrectionAction::autoApply(false)` et l'événement
 * `filament-prism:correction-applied`), puisqu'il n'y a ici ni colonne ni
 * `save()` génériques pour le faire.
 *
 *     FieldsCorrectionSubject::make(
 *         model: $this->record,
 *         key: 'day:'.$this->dayData['node_key'],
 *         fields: [CorrectableField::make('title'), CorrectableField::make('body')->html()],
 *         get: fn (): array => Arr::only($this->dayData, ['title', 'body']),
 *     )
 */
class FieldsCorrectionSubject implements CorrectionSubject
{
    /** @param array<int, CorrectableField> $fields */
    private function __construct(
        private readonly Model $model,
        private readonly string $key,
        private readonly array $fields,
        private readonly Closure $get,
    ) {}

    /**
     * @param  array<int, CorrectableField>  $fields
     * @param  Closure(): array<string, mixed>  $get
     */
    public static function make(Model $model, string $key, array $fields, Closure $get): self
    {
        return new self($model, $key, $fields, $get);
    }

    public function model(): Model
    {
        return $this->model;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    /** @return array<int, CorrectableField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function extractValues(): array
    {
        return ($this->get)();
    }
}
