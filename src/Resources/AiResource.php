<?php

namespace CharlesStOlive\FilamentPrism\Resources;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Services\AiRunner;
use CharlesStOlive\FilamentPrism\Support\TextDiffRenderer;
use CharlesStOlive\FilamentPrism\Tasks\AiTask;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Str;
use LogicException;
use Prism\Prism\Images\PendingRequest as ImageRequest;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Structured\PendingRequest as StructuredRequest;
use Prism\Prism\Text\PendingRequest as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
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
 *
 * **Une demande qui dure** (une image générée : une minute, parfois plus)
 * - `queued()` — la demande est enregistrée tout de suite, et un job fait
 *   l'appel en arrière-plan (`AiRunner::queue()`) ; son auteur est prévenu
 *   quand elle est prête. Son entrée doit alors tenir en JSON (des
 *   identifiants, pas des fichiers) : c'est tout ce que le job reçoit.
 * - `generatesImages()` — la réponse est une ou plusieurs images
 *   (`Prism::image()`), rangées sur le disque de la config ; `attachments()`
 *   y sont les photos à retravailler. `configureImageRequest()` règle la
 *   taille, la qualité...
 * - `resultSchema()` / `inputDisplaySchema()` — l'affichage d'une demande
 *   (modale, slide-over, volet, page « Demandes IA ») : des composants
 *   Filament, le plus souvent des `TextEntry` ; sans eux, une grille par défaut
 *   (voir `AiResultSchema`), nourrie par `sourcePreviews()` (les images de
 *   départ) et `describeInput()` (les réglages, lisibles).
 *
 * **Le cycle d'une demande** (voir `AiInteractionStatus`), une méthode par
 * transition, chacune avec un comportement par défaut :
 * - Soumettre — toute demande naît brouillon ; `interactionContext()` rend ce
 *   que l'appel a besoin de savoir en plus, lu dans la demande elle-même (le
 *   brouillon peut être soumis d'ailleurs que de là où il est né).
 * - Accepter — `canAccept()`, `acceptLabel()`, `acceptSchema()`, `accept()` :
 *   par défaut le résultat est simplement accepté ; une ressource qui sait
 *   l'appliquer (une image ajoutée à une bibliothèque...) le fait là.
 * - Affiner — `canRefine()`, `refineSchema()`, `refinePrompt()`,
 *   `refineAttachments()` : une nouvelle version, avec d'autres réglages et/ou
 *   ce qui ne va pas ; l'IA reçoit sa réponse précédente et la remarque.
 * - Ignorer, Relancer, Archiver, Désarchiver : communs à toutes.
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
        return (string) ($this->generatesImages() ? config('filament-prism.images.model') : config('filament-prism.model'));
    }

    /** L'icône de la ressource, dans la page « Demandes IA » et les actions qui la lancent. */
    public function icon(): string
    {
        return $this->generatesImages() ? 'heroicon-o-photo' : 'heroicon-o-sparkles';
    }

    // ── Une demande qui dure ──────────────────────────────────────────────

    /** L'appel se fait en arrière-plan (un job) plutôt que pendant la requête : voir `AiRunner::queue()`. */
    public function queued(): bool
    {
        return false;
    }

    /** La réponse est une ou plusieurs images (`Prism::image()`), et non du texte. */
    public function generatesImages(): bool
    {
        return false;
    }

    /**
     * Le temps laissé au provider pour répondre, en secondes ; `null` garde
     * celui de prism (`prism.request_timeout`, 30 s par défaut — trop court
     * pour générer une image).
     */
    public function timeout(): ?int
    {
        return $this->generatesImages() ? 240 : null;
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

    /**
     * La taille, la qualité... d'une image demandée (`withProviderOptions()`).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    public function configureImageRequest(ImageRequest $request, array $input, array $context): void {}

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

    // ── L'affichage d'une demande (« Demandes IA ») ───────────────────────

    /**
     * Le résultat d'une demande, en composants Filament — le plus souvent des `TextEntry` ; le
     * schéma porte la demande comme `record` (`TextEntry::make('output.summary')`). `null` :
     * l'affichage par défaut, une grille d'entrées (voir `AiResultSchema::defaultResult()`).
     *
     * @return array<int, Component>|null
     */
    public function resultSchema(AiInteraction $interaction): ?array
    {
        return null;
    }

    /**
     * Les réglages d'une demande, en composants Filament. `null` : ceux de `describeInput()`, en
     * grille (voir `AiResultSchema::defaultInput()`).
     *
     * @return array<int, Component>|null
     */
    public function inputDisplaySchema(AiInteraction $interaction): ?array
    {
        return null;
    }

    /**
     * Les images de départ d'une demande, pour les montrer à côté du résultat.
     *
     * @return array<int, array{url: string, label?: string|null}>
     */
    public function sourcePreviews(AiInteraction $interaction): array
    {
        return [];
    }

    /**
     * Les réglages d'une demande, lisibles : libellé => valeur. Par défaut, les
     * valeurs simples de l'entrée ; une ressource dit mieux ce qu'elles veulent dire.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function describeInput(array $input): array
    {
        return collect($input)
            ->filter(fn (mixed $value): bool => is_scalar($value) && $value !== '')
            ->mapWithKeys(fn (mixed $value, string $key): array => [
                Str::headline($key) => is_bool($value) ? ($value ? 'Oui' : 'Non') : (string) $value,
            ])
            ->all();
    }

    // ── Soumettre ────────────────────────────────────────────────────────

    /**
     * Ce que l'appel doit savoir en plus de l'entrée, lu dans la demande elle-même (`meta`...) :
     * un brouillon peut être soumis, ou une demande affinée, depuis une autre requête que celle
     * où il est né — ce que l'appelant savait alors (`$context` d'`AiRunner::run()`) n'y est plus.
     *
     * @return array<string, mixed>
     */
    public function interactionContext(AiInteraction $interaction): array
    {
        return [];
    }

    // ── Accepter ─────────────────────────────────────────────────────────

    /** Ce résultat peut-il être accepté d'ici (une carte de « Demandes IA ») ? */
    public function canAccept(AiInteraction $interaction): bool
    {
        return true;
    }

    /** Le libellé du bouton — « Ajouter à la bibliothèque » pour une ressource qui applique son résultat. */
    public function acceptLabel(): string
    {
        return 'Accepter';
    }

    /**
     * Ce qu'on choisit en acceptant (la période où ranger une image...) ; `null` : on accepte d'un clic.
     *
     * @return array<int, Component>|null
     */
    public function acceptSchema(AiInteraction $interaction): ?array
    {
        return null;
    }

    /**
     * Accepte le résultat. Par défaut, il est seulement accepté ; une ressource qui l'applique
     * quelque part le fait ici, puis le marque (`AiInteraction::markApplied()`).
     *
     * @param  array<string, mixed>  $data  Ce qu'on a choisi dans `acceptSchema()`.
     * @return string|null Le message de la notification de succès.
     */
    public function accept(AiInteraction $interaction, array $data = []): ?string
    {
        $interaction->markAccepted();

        return null;
    }

    // ── Affiner ──────────────────────────────────────────────────────────

    public function canRefine(AiInteraction $interaction): bool
    {
        return true;
    }

    /**
     * Le formulaire d'« Affiner », rempli avec l'entrée de la demande : ses réglages
     * (`inputSchema()`), et ce qui ne va pas (`feedback`).
     *
     * @return array<int, Component>
     */
    public function refineSchema(AiInteraction $interaction): array
    {
        return [
            ...($this->inputSchema() ?? []),
            Textarea::make('feedback')
                ->label('Ce qui ne va pas')
                ->placeholder('Facultatif — par exemple : « le ciel est trop sombre », « garde le tutoiement ».')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }

    /** Le message qui suit la réponse précédente, quand on affine avec une remarque. */
    public function refinePrompt(string $feedback): string
    {
        return "Ta réponse précédente ne convient pas tout à fait. Voici ce qui ne va pas :\n\n{$feedback}\n\nRefais-la en en tenant compte, dans le même format.";
    }

    /**
     * Les images à retravailler quand on affine une ressource d'image : par défaut, celles que la
     * version précédente a produites. Vide : les pièces jointes d'origine (`attachments()`).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<int, Media>
     */
    public function refineAttachments(AiInteraction $previous, array $input, array $context): array
    {
        return collect($previous->images())
            ->map(fn (array $image): Image => Image::fromStoragePath($image['path'], $image['disk']))
            ->all();
    }

    /**
     * Où mène la notification d'une demande terminée ; `null` : sa page dans
     * « Demandes IA », quand le panel l'a (voir `FilamentPrismPlugin`).
     */
    public function resultUrl(AiInteraction $interaction): ?string
    {
        return null;
    }
}
