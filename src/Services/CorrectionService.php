<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * La correction d'un `CorrectionSubject` (ou d'un groupe) : ce que la ressource de correction
 * (`OrthographyTask`) ne peut pas savoir seule — quel texte envoyer, à quel modèle rattacher la
 * réponse, et comment l'écrire dans ce modèle. L'appel lui-même (réutilisation d'une réponse déjà
 * payée, prompt, schéma, persistance) est celui de toute `AiResource` : `AiRunner`.
 */
class CorrectionService
{
    public function __construct(
        private readonly AiTaskRegistry $tasks,
        private readonly AiRunner $runner,
    ) {}

    /**
     * Appelle l'IA pour corriger `$subject` et persiste le résultat — ou retrouve la réponse
     * `pending` déjà payée pour ce même texte (voir `AiRunner::run()`).
     *
     * @throws AiProviderException
     */
    public function correct(CorrectionSubject $subject, string $taskKey = 'orthography', ?Model $trackable = null): AiInteraction
    {
        return $this->runner->run(
            $this->tasks->resource($taskKey),
            input: $subject->extractValues(),
            context: ['fields' => $subject->fields()],
            attachTo: $subject->model(),
            subjectKey: $subject->key(),
            trackable: $trackable,
            meta: ['labels' => CorrectableField::labelsByName($subject->fields())],
        );
    }

    /**
     * Corrige plusieurs sujets — les périodes d'un voyage entier, par exemple
     * — en **un seul appel IA**, plutôt qu'un par sujet : un schéma Prism
     * répété (`CorrectableField::toGroupedObjectSchema()`), une seule
     * `AiInteraction`. Chaque sujet doit partager les mêmes champs (voir
     * `CorrectionSubjectGroup`).
     *
     * `output.items` associe la correction reçue à son sujet par sa `key` —
     * jamais par position : l'IA ne garantit ni l'ordre ni la présence de
     * chaque élément demandé.
     *
     * @throws AiProviderException
     */
    public function correctGroup(CorrectionSubjectGroup $group, string $taskKey = 'orthography', ?Model $trackable = null): AiInteraction
    {
        $input = [
            'items' => collect($group->subjects())
                ->map(fn (CorrectionSubject $subject, string $key): array => ['key' => $key, ...$subject->extractValues()])
                ->values()
                ->all(),
        ];

        return $this->runner->run(
            $this->tasks->resource($taskKey),
            input: $input,
            context: ['fields' => $group->fields(), 'grouped' => true],
            attachTo: $group->model(),
            subjectKey: $group->key(),
            trackable: $trackable,
            meta: ['labels' => CorrectableField::labelsByName($group->fields())],
        );
    }

    /** @see AiRunner::providerIsConfigured() */
    public function providerIsConfigured(string $provider): bool
    {
        return $this->runner->providerIsConfigured($provider);
    }

    /**
     * Écrit le résultat dans le modèle corrigé et le persiste. Seulement
     * valable quand le sujet est le modèle lui-même (`Correctable`) : pour un
     * `FieldsCorrectionSubject`, écrire le résultat est la responsabilité de
     * l'appelant (voir `CorrectionReview` et `CorrectionAction::autoApply()`).
     */
    public function apply(AiInteraction $interaction, ?array $onlyFields = null): void
    {
        $correctable = $interaction->correctable;

        if (! $correctable instanceof Model || ! in_array(Correctable::class, class_uses_recursive($correctable), true)) {
            throw new RuntimeException('Cette interaction ne référence pas un modèle Correctable : elle ne peut pas s’appliquer toute seule (voir CorrectionAction::autoApply(false)).');
        }

        $values = $onlyFields === null
            ? ($interaction->output ?? [])
            : array_intersect_key($interaction->output ?? [], array_flip($onlyFields));

        $correctable->applyValues($values);
        $correctable->save();
        $interaction->markApplied();
    }

    public function discard(AiInteraction $interaction): void
    {
        $interaction->markDiscarded();
    }
}
