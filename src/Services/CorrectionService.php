<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;

class CorrectionService
{
    public function __construct(private readonly AiTaskRegistry $tasks) {}

    /**
     * Appelle l'IA pour corriger `$correctable` et persiste le résultat.
     *
     * Réutilise une interaction `pending` déjà en base pour ce
     * `(correctable, task)` plutôt que de rappeler l'IA : si l'utilisateur
     * avait fermé l'onglet sans conclure, on retrouve la réponse déjà payée
     * au lieu de payer les tokens une seconde fois.
     */
    public function correct(Model&Correctable $correctable, string $taskKey = 'orthography', ?Model $trackable = null): AiInteraction
    {
        $pending = AiInteraction::query()
            ->where('correctable_type', $correctable::class)
            ->where('correctable_id', $correctable->getKey())
            ->where('task', $taskKey)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pending) {
            return $pending;
        }

        $task = $this->tasks->get($taskKey);
        $input = $correctable->extractCorrectableValues();

        $response = Prism::structured()
            ->using($task->provider(), $task->model())
            ->withSchema($correctable::prismObjectSchema())
            ->withSystemPrompt($task->systemPrompt())
            ->withPrompt(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->asStructured();

        return AiInteraction::create([
            'user_id' => auth()->id(),
            'task' => $task->key(),
            'provider' => $task->provider(),
            'model' => $task->model(),
            'correctable_type' => $correctable::class,
            'correctable_id' => $correctable->getKey(),
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

    public function apply(AiInteraction $interaction, ?array $onlyFields = null): void
    {
        $correctable = $interaction->correctable;

        if (! $correctable instanceof Model || ! in_array(Correctable::class, class_uses_recursive($correctable), true)) {
            throw new \RuntimeException('Cette interaction ne référence plus un modèle Correctable.');
        }

        $values = $onlyFields === null
            ? $interaction->output
            : array_intersect_key($interaction->output ?? [], array_flip($onlyFields));

        $correctable->applyCorrectableValues($values);
        $correctable->save();
        $interaction->markApplied();
    }

    public function discard(AiInteraction $interaction): void
    {
        $interaction->markDiscarded();
    }
}
