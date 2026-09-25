<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Jobs\RunAiInteraction;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Support\AiCost;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use CharlesStOlive\FilamentPrism\Support\AiProviders;
use CharlesStOlive\FilamentPrism\Support\ExchangeRates;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Le cycle commun à toute `AiResource` : réutiliser une réponse déjà payée,
 * sinon appeler l'IA, résoudre sa réponse (`AiResource::resolve()`) et la
 * persister dans une `AiInteraction`.
 *
 * La demande est enregistrée **avant** l'appel, pas après : une coupure en
 * plein appel laisse une trace (`running`, puis `failed`), et une demande mise
 * en file (`queue()`) existe dès qu'on la fait. Un appel qui échoue reste
 * aussi, `failed` avec son message : les stats comptent les échecs.
 */
class AiRunner
{
    public function __construct(private readonly AiTaskRegistry $tasks) {}

    /**
     * L'appel, tout de suite, pendant la requête.
     *
     * @param  array<string, mixed>  $input  Ce qui est envoyé, persisté, et comparé pour réutiliser une réponse.
     * @param  array<string, mixed>  $context  Ce que l'appelant sait en plus (jamais persisté) ; complète `AiResource::context()`.
     * @param  Model|null  $attachTo  Le modèle auquel l'interaction se rattache pour durer (`correctable`).
     * @param  string|null  $subjectKey  Distingue plusieurs sujets d'un même modèle — ou, sans modèle, identifie le sujet à lui seul (ex. l'empreinte d'un fichier).
     * @param  array<string, mixed>  $meta
     *
     * @throws AiProviderException
     */
    public function run(AiResource $resource, array $input, array $context = [], ?Model $attachTo = null, ?string $subjectKey = null, ?Model $trackable = null, array $meta = []): AiInteraction
    {
        if ($pending = $this->reusablePending($resource->key(), $attachTo, $subjectKey, $input)) {
            return $pending;
        }

        $interaction = $this->create($resource, $input, $attachTo, $subjectKey, $trackable, $meta, AiInteraction::STATUS_RUNNING);

        return $this->execute($interaction, $context);
    }

    /**
     * La demande est enregistrée (`queued`) et un job fera l'appel
     * (`RunAiInteraction`) : la réponse ne bloque pas la page. Contrairement à
     * `run()`, une demande identique n'en réutilise pas une autre — une image
     * redemandée avec les mêmes réglages en est une nouvelle version.
     *
     * Rien de ce que sait l'appelant ne suit le job, sinon `$input` : la
     * ressource retrouve le reste elle-même (`AiResource::context()`).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta
     */
    public function queue(AiResource $resource, array $input, ?Model $attachTo = null, ?string $subjectKey = null, ?Model $trackable = null, array $meta = [], ?AiInteraction $parent = null): AiInteraction
    {
        $interaction = $this->create($resource, $input, $attachTo, $subjectKey, $trackable, $meta, AiInteraction::STATUS_QUEUED, $parent);

        RunAiInteraction::dispatch($interaction->getKey());

        return $interaction;
    }

    /**
     * Refait une demande — avec d'autres réglages (`$input` remplace les
     * valeurs de même clé), ou les mêmes (après un échec) : une nouvelle
     * demande du même fil, dont `$from` est le parent.
     *
     * @param  array<string, mixed>  $input
     */
    public function rerun(AiInteraction $from, array $input = []): AiInteraction
    {
        return $this->queue(
            $this->tasks->resource($from->task),
            input: array_replace($from->input ?? [], $input),
            attachTo: $from->correctable,
            subjectKey: $from->subject_key,
            trackable: $from->trackable,
            meta: Arr::only($from->meta ?? [], ['labels']),
            parent: $from,
        );
    }

    /**
     * Fait l'appel d'une demande déjà enregistrée, et y écrit la réponse
     * (`pending`) — ou l'échec (`failed`, puis l'exception relancée).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws AiProviderException
     */
    public function execute(AiInteraction $interaction, array $context = []): AiInteraction
    {
        $resource = $this->tasks->resource($interaction->task);
        $input = $interaction->input ?? [];

        if ($interaction->status !== AiInteraction::STATUS_RUNNING || $interaction->started_at === null) {
            $interaction->markRunning();
        }

        try {
            $context = [...$resource->context($input), ...$context];
            [$raw, $usage, $details] = $resource->generatesImages()
                ? $this->callImage($resource, $interaction, $input, $context)
                : $this->call($resource, $input, $context);
            $output = $resource->resolve($raw, $input, $context);
        } catch (AiProviderException $exception) {
            $interaction->markFailed($exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $interaction->markFailed('La réponse de l’IA n’a pas pu être traitée.');

            throw $exception;
        }

        $meta = $interaction->meta ?? [];

        if ($output !== $raw) {
            $meta['raw'] = $raw;
        }

        if ($details !== []) {
            $meta['usage'] = $details;
        }

        $cost = AiCost::of($interaction->provider, $interaction->model, $usage->promptTokens, $usage->completionTokens, (array) ($details['input_tokens_details'] ?? []));
        $currency = AiProviders::currency($interaction->provider);

        $interaction->forceFill([
            'kind' => $resource->generatesImages() ? 'image' : ($resource->responseSchema($input, $context) === null ? 'text' : 'structured'),
            'output' => $output,
            'meta' => $meta === [] ? null : $meta,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->promptTokens + $usage->completionTokens,
            'cost' => $cost,
            'currency' => $cost === null ? null : $currency,
            // Au dernier taux BCE connu ; complété plus tard si aucun ne l'est encore (voir AiBillingSync).
            'cost_eur' => $cost === null ? null : app(ExchangeRates::class)->toEur($cost, $currency, now()),
            'status' => AiInteraction::STATUS_PENDING,
            'error' => null,
            'finished_at' => now(),
        ])->save();

        return $interaction;
    }

    /**
     * Le provider a-t-il de quoi appeler l'IA ? Une clé non vide quand ce
     * provider en a une (`config('prism.providers.<provider>.api_key')`) —
     * absente de la config pour un provider qui n'en a pas besoin (ex.
     * ollama en local), auquel cas rien à vérifier.
     */
    public function providerIsConfigured(string $provider): bool
    {
        $config = config("prism.providers.{$provider}", []);

        return ! array_key_exists('api_key', $config) || filled($config['api_key']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $meta
     */
    private function create(AiResource $resource, array $input, ?Model $attachTo, ?string $subjectKey, ?Model $trackable, array $meta, string $status, ?AiInteraction $parent = null): AiInteraction
    {
        // Le panel d'où part la demande : c'est là que la notification de fin mène (voir RunAiInteraction).
        $panel = Filament::getCurrentPanel()?->getId();

        if ($panel !== null) {
            $meta['panel'] ??= $panel;
        }

        return AiInteraction::create([
            'user_id' => auth()->id(),
            'task' => $resource->key(),
            'provider' => $resource->provider(),
            'model' => $resource->model(),
            'correctable_type' => $attachTo ? $attachTo::class : null,
            'correctable_id' => $attachTo?->getKey(),
            'subject_key' => $subjectKey,
            'trackable_type' => $trackable?->getMorphClass(),
            'trackable_id' => $trackable?->getKey(),
            'input' => $input,
            'meta' => $meta === [] ? null : $meta,
            'status' => $status,
            'started_at' => $status === AiInteraction::STATUS_RUNNING ? now() : null,
            'thread_id' => $parent->thread_id ?? (string) Str::uuid(),
            'parent_interaction_id' => $parent?->getKey(),
        ]);
    }

    /**
     * Structuré quand la ressource déclare un `responseSchema()`, texte libre
     * sinon (`['text' => ...]`).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: Usage, 2: array<string, mixed>}
     *
     * @throws AiProviderException Clé refusée, réseau, quota... — jamais l'exception brute de
     *                             Prism/du client HTTP, pour que l'appelant puisse en montrer le
     *                             message tel quel plutôt que planter.
     */
    private function call(AiResource $resource, array $input, array $context): array
    {
        $schema = $resource->responseSchema($input, $context);
        $tools = $resource->tools($input, $context);

        try {
            $request = ($schema === null ? Prism::text() : Prism::structured()->withSchema($schema))
                ->using($resource->provider(), $resource->model())
                ->withSystemPrompt($resource->systemPrompt())
                ->withPrompt($resource->prompt($input, $context), $resource->attachments($input, $context));

            if ($resource->timeout() !== null) {
                $request->withClientOptions(['timeout' => $resource->timeout()]);
            }

            if ($tools !== []) {
                $request->withTools($tools)->withMaxSteps($resource->maxSteps());
            }

            $resource->configureRequest($request);

            if ($schema === null) {
                $response = $request->asText();

                return [['text' => $response->text], $response->usage, []];
            }

            $response = $request->asStructured();

            return [$response->structured ?? [], $response->usage, []];
        } catch (Throwable $exception) {
            throw AiProviderException::fromThrowable($exception);
        }
    }

    /**
     * Une ou plusieurs images (`Prism::image()`) : les photos jointes
     * (`attachments()`) sont retravaillées, le résultat rangé sur le disque de
     * la config — `output = ['images' => [['disk', 'path', 'mime_type'], ...]]`.
     *
     * Un modèle d'image n'a pas de prompt système à part : il est placé en
     * tête du prompt.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: Usage, 2: array<string, mixed>}
     *
     * @throws AiProviderException
     */
    private function callImage(AiResource $resource, AiInteraction $interaction, array $input, array $context): array
    {
        try {
            $prompt = trim($resource->systemPrompt()."\n\n".$resource->prompt($input, $context));

            $request = Prism::image()
                ->using($resource->provider(), $resource->model())
                ->withPrompt($prompt, $resource->attachments($input, $context));

            if ($resource->timeout() !== null) {
                $request->withClientOptions(['timeout' => $resource->timeout()]);
            }

            $resource->configureImageRequest($request, $input, $context);

            $response = $request->generate();
        } catch (Throwable $exception) {
            throw AiProviderException::fromThrowable($exception);
        }

        if (! $response->hasImages()) {
            throw new AiProviderException('L’IA n’a renvoyé aucune image.');
        }

        $format = (string) ($response->additionalContent['output_format'] ?? 'png');
        $images = [];

        foreach ($response->images as $index => $image) {
            $images[] = $this->storeImage($interaction, $image, $index + 1, $format);
        }

        return [['images' => $images], $response->usage, Arr::only($response->additionalContent, ['input_tokens_details', 'size', 'quality'])];
    }

    /** @return array{disk: string, path: string, mime_type: string, revised_prompt?: string} */
    private function storeImage(AiInteraction $interaction, GeneratedImage $image, int $number, string $format): array
    {
        $disk = (string) config('filament-prism.images.disk', 'public');
        $path = trim((string) config('filament-prism.images.directory', 'ai-images'), '/')."/{$interaction->getKey()}/{$number}.{$format}";

        $contents = $image->base64 !== null
            ? base64_decode($image->base64, strict: true)
            : Http::timeout(60)->get((string) $image->url)->throw()->body();

        if ($contents === false || $contents === '') {
            throw new AiProviderException('L’image renvoyée par l’IA est illisible.');
        }

        Storage::disk($disk)->put($path, $contents);

        return array_filter([
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/'.($format === 'jpg' ? 'jpeg' : $format),
            'revised_prompt' => $image->revisedPrompt,
        ]);
    }

    /**
     * L'interaction `pending` de ce (sujet, ressource), seulement si ce qu'elle a reçu est encore ce
     * qu'on s'apprête à envoyer — si l'utilisateur avait fermé l'onglet sans conclure, on retrouve la
     * réponse déjà payée au lieu de payer les tokens une seconde fois. Une interaction `pending` dont
     * l'entrée a changé depuis (texte édité à la main entre-temps) est marquée `discarded` en passant.
     *
     * Sans modèle d'attache, seul `subjectKey` identifie le sujet (ex. l'empreinte d'un fichier) :
     * l'interaction peut alors avoir été rattachée après coup à ce qu'elle a produit — elle reste
     * réutilisable.
     *
     * @param  array<string, mixed>  $input
     */
    private function reusablePending(string $resourceKey, ?Model $attachTo, ?string $subjectKey, array $input): ?AiInteraction
    {
        if ($attachTo === null && $subjectKey === null) {
            return null;
        }

        $pending = AiInteraction::query()
            ->when($attachTo !== null, fn (Builder $query) => $query
                ->where('correctable_type', $attachTo::class)
                ->where('correctable_id', $attachTo->getKey()))
            ->when(
                $subjectKey === null,
                fn (Builder $query) => $query->whereNull('subject_key'),
                fn (Builder $query) => $query->where('subject_key', $subjectKey),
            )
            ->where('task', $resourceKey)
            ->where('status', AiInteraction::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($pending === null) {
            return null;
        }

        if ($pending->input === $input) {
            return $pending;
        }

        $pending->markDiscarded();

        return null;
    }
}
