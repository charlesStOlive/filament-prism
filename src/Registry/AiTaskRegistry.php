<?php

namespace CharlesStOlive\FilamentPrism\Registry;

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

    /** @return Collection<string, AiTask> */
    public function all(): Collection
    {
        return $this->tasks ??= collect(config('filament-prism.tasks', []))
            ->map(fn (string $class): AiTask => app($class))
            ->keyBy(fn (AiTask $task): string => $task->key());
    }

    public function get(string $key): AiTask
    {
        return $this->all()->get($key)
            ?? throw new InvalidArgumentException("Aucune tâche filament-prism enregistrée sous la clé [{$key}].");
    }

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }
}
