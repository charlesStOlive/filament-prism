<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Throwable;

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
        $response = $this->callAi($task->provider(), $task->model(), $task->systemPrompt(), CorrectableField::toObjectSchema($subject->fields()), $input);
        $output = CorrectableField::sanitizeValues($subject->fields(), $response->structured ?? []);

        return $this->persist($task->key(), $task->provider(), $task->model(), $model, $subject->key(), $trackable, $input, $output, $response->usage, CorrectableField::labelsByName($subject->fields()));
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

        $response = $this->callAi($task->provider(), $task->model(), $task->systemPrompt(), CorrectableField::toGroupedObjectSchema($group->fields()), $input);

        // Même filtre que correct() (voir CorrectableField::sanitizeValues()), item par item ; la clé
        // elle-même est repassée en chaîne — un item dont elle manque ou n'est pas exploitable est
        // rejeté, il ne pourrait de toute façon se rattacher à aucun sujet demandé.
        $items = collect($response->structured['items'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && is_scalar($item['key'] ?? null))
            ->map(fn (array $item): array => ['key' => (string) $item['key'], ...CorrectableField::sanitizeValues($group->fields(), $item)])
            ->values()
            ->all();

        return $this->persist($task->key(), $task->provider(), $task->model(), $model, $group->key(), $trackable, $input, ['items' => $items], $response->usage, CorrectableField::labelsByName($group->fields()));
    }

    /**
     * Le provider a-t-il de quoi appeler l'IA ? Une clé non vide quand ce
     * provider en a une (`config('prism.providers.<provider>.api_key')`) —
     * absente de la config pour un provider qui n'en a pas besoin (ex.
     * ollama en local), auquel cas rien à vérifier. Sert à désactiver
     * proprement `CorrectionAction`/`GroupCorrectionAction` plutôt que de
     * lancer un appel voué à échouer.
     */
    public function providerIsConfigured(string $provider): bool
    {
        $config = config("prism.providers.{$provider}", []);

        return ! array_key_exists('api_key', $config) || filled($config['api_key']);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AiProviderException Clé refusée, réseau, quota... — jamais l'exception brute de
     *                              Prism/du client HTTP, pour que l'appelant puisse en montrer le
     *                              message tel quel plutôt que planter (voir CorrectionAction).
     */
    private function callAi(string $provider, string $model, string $systemPrompt, Schema $schema, array $input): Response
    {
        try {
            return Prism::structured()
                ->using($provider, $model)
                ->withSchema($schema)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                ->asStructured();
        } catch (Throwable $exception) {
            throw AiProviderException::fromThrowable($exception);
        }
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
     * @param  array<string, string>  $labels  Le libellé de chaque champ (voir
     *                                          `CorrectableField::labelsByName()`), posé dans `meta`
     *                                          pour que le renderer puisse s'en servir plus tard sans
     *                                          connaître le `CorrectionSubject` d'origine — lui ne
     *                                          reçoit que l'`AiInteraction` déjà persistée.
     */
    private function persist(string $taskKey, string $provider, string $model, Model $correctable, ?string $subjectKey, ?Model $trackable, array $input, ?array $output, Usage $usage, array $labels = []): AiInteraction
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
            'meta' => $labels === [] ? null : ['labels' => $labels],
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
