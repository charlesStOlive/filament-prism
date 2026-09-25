<?php

namespace CharlesStOlive\FilamentPrism\Resources;

use CharlesStOlive\FilamentPrism\Services\AiRunner;
use CharlesStOlive\FilamentPrism\Support\TextDiffRenderer;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Str;
use LogicException;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Structured\PendingRequest as StructuredRequest;
use Prism\Prism\Text\PendingRequest as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Media;

/**
 * Une « ressource IA » : tout ce qui décrit un usage de l'IA, du formulaire qui
 * la précède à la soumission de sa réponse, au même endroit — comme une
 * ressource Filament décrit un modèle. Déclarée dans
 * `config('filament-prism.tasks')`, retrouvée par sa `key()`.
 *
 * Le cycle, dans l'ordre (voir `AiRunner::run()` et `AiResourceAction`) :
 *
 * **Avant l'appel**
 * - `inputSchema()` — le formulaire Filament à remplir avant l'appel (un
 *   fichier, un sélecteur...) ; `null` quand le code appelant fournit déjà tout
 *   (la correction orthographique : le texte vient du modèle édité).
 * - `handleInput()` — transforme ce formulaire en un ou plusieurs appels (un
 *   par fichier, par exemple) et renvoie l'état initial de la réception.
 * - `context()` — ce que la ressource va chercher elle-même pour nourrir le
 *   prompt ou le schéma (une requête en base...). Jamais persisté.
 *
 * **L'appel**
 * - `systemPrompt()` / `prompt()` / `attachments()` — ce qu'on envoie.
 * - `responseSchema()` — la forme du retour : un JSON structuré (schéma
 *   Prism), ou `null` pour du texte libre (`output = ['text' => ...]`).
 * - `tools()` / `maxSteps()` — les fonctions que l'IA peut appeler pendant
 *   l'appel ; `configureRequest()` pour le reste (température, options du
 *   provider...).
 *
 * **Après l'appel**
 * - `resolve()` — normalise et complète la réponse en PHP, selon ce qui a été
 *   reçu (dates, rapprochement en base, cohérence, avertissements). C'est ce
 *   résultat qui est persisté dans `AiInteraction.output` (la réponse brute
 *   reste dans `meta.raw` quand elle diffère).
 * - `receptionSchema()` — le formulaire de vérification de ce résultat.
 * - `apply()` — la soumission de cette vérification.
 *
 * Une ressource qui a sa propre revue (la correction : `CorrectionReview`,
 * affichée par `rendererClass()`) n'a besoin ni de `receptionSchema()` ni
 * d'`apply()`.
 */
abstract class AiResource implements AiTask
{
    abstract public function key(): string;

    public function label(): string
    {
        return Str::headline($this->key());
    }

    public function provider(): string
    {
        return (string) config('filament-prism.provider');
    }

    public function model(): string
    {
        return (string) config('filament-prism.model');
    }

    // ── Avant l'appel ─────────────────────────────────────────────────────

    /** @return array<int, Component>|null */
    public function inputSchema(): ?array
    {
        return null;
    }

    /**
     * Les données du formulaire d'entrée (`inputSchema()`), telles que saisies
     * — un `FileUpload` y est encore un `TemporaryUploadedFile` — vers l'état
     * initial de la réception (`receptionSchema()`). Par défaut : un seul
     * appel, dont le résultat est la réception.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handleInput(array $data): array
    {
        $interaction = app(AiRunner::class)->run($this, $data);

        return ['interaction_id' => $interaction->getKey(), ...($interaction->output ?? [])];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function context(array $input): array
    {
        return [];
    }

    // ── L'appel ───────────────────────────────────────────────────────────

    abstract public function systemPrompt(): string;

    /**
     * `JSON_UNESCAPED_SLASHES` n'est pas cosmétique : sans lui, chaque `</p>`
     * d'un HTML envoyé devient `<\/p>`, et l'IA imite ce style dans sa réponse.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    public function prompt(array $input, array $context): string
    {
        return json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Documents ou images joints au prompt (`Document::fromLocalPath()`...).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<int, Media>
     */
    public function attachments(array $input, array $context): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    public function responseSchema(array $input, array $context): ?ObjectSchema
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<int, Tool>
     */
    public function tools(array $input, array $context): array
    {
        return [];
    }

    /** Le nombre d'allers-retours autorisés quand l'IA appelle des `tools()`. */
    public function maxSteps(): int
    {
        return 1;
    }

    public function configureRequest(StructuredRequest|TextRequest $request): void {}

    // ── Après l'appel ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $output  La réponse de l'IA, déjà décodée.
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolve(array $output, array $input, array $context): array
    {
        return $output;
    }

    /** @return array<int, Component>|null */
    public function receptionSchema(): ?array
    {
        return null;
    }

    /** @param  array<string, mixed>  $data  L'état du formulaire de réception, vérifié par l'utilisateur. */
    public function apply(array $data): void
    {
        throw new LogicException(static::class.' n’a pas de réception à soumettre (apply()).');
    }

    /** L'affichage d'une interaction dans la revue de correction (`CorrectionReview`). */
    public function rendererClass(): string
    {
        return TextDiffRenderer::class;
    }
}
