<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
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

        $pending = AiInteraction::query()
            ->where('correctable_type', $model::class)
            ->where('correctable_id', $model->getKey())
            ->when(
                $subject->key() === null,
                fn (Builder $query) => $query->whereNull('subject_key'),
                fn (Builder $query) => $query->where('subject_key', $subject->key()),
            )
            ->where('task', $taskKey)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pending) {
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

        return AiInteraction::create([
            'user_id' => auth()->id(),
            'task' => $task->key(),
            'provider' => $task->provider(),
            'model' => $task->model(),
            'correctable_type' => $model::class,
            'correctable_id' => $model->getKey(),
            'subject_key' => $subject->key(),
            'trackable_type' => $trackable?->getMorphClass(),
            'trackable_id' => $trackable?->getKey(),
            'input' => $input,
            'output' => $response->structured,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
            'total_tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
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
