<?php

namespace CharlesStOlive\FilamentPrism\Registry;

use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Résout les tâches listées dans `config('filament-prism.tasks')` par clé.
 * Une application ajoute les siennes en republiant/éditant ce fichier, sans
 * toucher au package (même principe que `SchemaRegistry` dans
 * filament-orchestrator).
 */
class AiTaskRegistry
{
    /** @var Collection<string, AiTask>|null */
    private ?Collection $tasks = null;

    /** @var array<int, class-string<AiTask>> Les classes d'où viennent `$tasks`. */
    private array $classes = [];

    /**
     * Relu si la configuration a changé depuis : les droits des tâches sont déclarés au démarrage (voir
     * `AiPermissions`), avant qu'une application ou un test n'ajoute les siennes.
     *
     * @return Collection<string, AiTask>
     */
    public function all(): Collection
    {
        $classes = (array) config('filament-prism.tasks', []);

        if ($this->tasks !== null && $classes === $this->classes) {
            return $this->tasks;
        }

        $this->classes = $classes;

        return $this->tasks = collect($classes)
            ->map(fn (string $class): AiTask => app($class))
            ->keyBy(fn (AiTask $task): string => $task->key());
    }

    public function get(string $key): AiTask
    {
        return $this->all()->get($key)
            ?? throw new InvalidArgumentException("Aucune tâche filament-prism enregistrée sous la clé [{$key}].");
    }

    /** Une tâche qui porte tout le cycle d'appel (voir `AiResource`) — `AiRunner` n'accepte qu'elles. */
    public function resource(string $key): AiResource
    {
        $task = $this->get($key);

        return $task instanceof AiResource
            ? $task
            : throw new InvalidArgumentException('La tâche filament-prism ['.$key.'] doit étendre '.AiResource::class.'.');
    }

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }
}
