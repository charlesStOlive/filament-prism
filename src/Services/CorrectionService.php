<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

class CorrectionService
{
    public function __construct(private readonly AiTaskRegistry $tasks) {}

    /**
     * Appelle l'IA pour corriger `$subject` et persiste le résultat.
     *
     * Réutilise une interaction `pending` déjà en base pour ce
     * `(modèle, sujet, tâche)` plutôt que de rappeler l'IA : si l'utilisateur
     * avait fermé l'onglet sans conclure, on retrouve la réponse déjà payée
     * au lieu de payer les tokens une seconde fois.
     */
    public function correct(CorrectionSubject $subject, string $taskKey = 'orthography', ?Model $trackable = null): AiInteraction
    {
        $model = $subject->model();

        if ($pending = $this->findPending($model, $subject->key(), $taskKey)) {
            return $pending;
        }

        $task = $this->tasks->get($taskKey);
        $input = $subject->extractValues();

        $response = Prism::structured()
            ->using($task->provider(), $task->model())
            ->withSchema(CorrectableField::toObjectSchema($subject->fields()))
            ->withSystemPrompt($task->systemPrompt())
            ->withPrompt(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->asStructured();

        return $this->persist($task->key(), $task->provider(), $task->model(), $model, $subject->key(), $trackable, $input, $response->structured, $response->usage);
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
     */
    public function correctGroup(CorrectionSubjectGroup $group, string $taskKey = 'orthography', ?Model $trackable = null): AiInteraction
    {
        $model = $group->model();

        if ($pending = $this->findPending($model, $group->key(), $taskKey)) {
            return $pending;
        }

        $task = $this->tasks->get($taskKey);

        $input = [
            'items' => collect($group->subjects())
                ->map(fn (CorrectionSubject $subject, string $key): array => ['key' => $key, ...$subject->extractValues()])
                ->values()
                ->all(),
        ];

        $response = Prism::structured()
            ->using($task->provider(), $task->model())
            ->withSchema(CorrectableField::toGroupedObjectSchema($group->fields()))
            ->withSystemPrompt($task->systemPrompt())
            ->withPrompt(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->asStructured();

        return $this->persist($task->key(), $task->provider(), $task->model(), $model, $group->key(), $trackable, $input, $response->structured, $response->usage);
    }

    private function findPending(Model $model, ?string $subjectKey, string $taskKey): ?AiInteraction
    {
        return AiInteraction::query()
            ->where('correctable_type', $model::class)
            ->where('correctable_id', $model->getKey())
            ->when(
                $subjectKey === null,
                fn (Builder $query) => $query->whereNull('subject_key'),
                fn (Builder $query) => $query->where('subject_key', $subjectKey),
            )
            ->where('task', $taskKey)
            ->where('status', 'pending')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $output
     */
    private function persist(string $taskKey, string $provider, string $model, Model $correctable, ?string $subjectKey, ?Model $trackable, array $input, ?array $output, Usage $usage): AiInteraction
    {
        return AiInteraction::create([
            'user_id' => auth()->id(),
            'task' => $taskKey,
            'provider' => $provider,
            'model' => $model,
            'correctable_type' => $correctable::class,
            'correctable_id' => $correctable->getKey(),
            'subject_key' => $subjectKey,
            'trackable_type' => $trackable?->getMorphClass(),
            'trackable_id' => $trackable?->getKey(),
            'input' => $input,
            'output' => $output,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->promptTokens + $usage->completionTokens,
            'status' => 'pending',
            'thread_id' => (string) Str::uuid(),
        ]);
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
