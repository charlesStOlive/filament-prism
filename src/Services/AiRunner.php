<?php

namespace CharlesStOlive\FilamentPrism\Services;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Le cycle commun à toute `AiResource` : réutiliser une réponse déjà payée,
 * sinon appeler l'IA, résoudre sa réponse (`AiResource::resolve()`) et la
 * persister dans une `AiInteraction` — dès la réponse, jamais tenue seulement
 * en état Livewire.
 */
class AiRunner
{
    /**
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

        $context = [...$resource->context($input), ...$context];
        [$raw, $usage] = $this->call($resource, $input, $context);
        $output = $resource->resolve($raw, $input, $context);

        if ($output !== $raw) {
            $meta['raw'] = $raw;
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
            'output' => $output,
            'meta' => $meta === [] ? null : $meta,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->promptTokens + $usage->completionTokens,
            'status' => 'pending',
            'thread_id' => (string) Str::uuid(),
        ]);
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
     * Structuré quand la ressource déclare un `responseSchema()`, texte libre
     * sinon (`['text' => ...]`).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: Usage}
     *
     * @throws AiProviderException Clé refusée, réseau, quota... — jamais l'exception brute de
     *                              Prism/du client HTTP, pour que l'appelant puisse en montrer le
     *                              message tel quel plutôt que planter.
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

            if ($tools !== []) {
                $request->withTools($tools)->withMaxSteps($resource->maxSteps());
            }

            $resource->configureRequest($request);

            if ($schema === null) {
                $response = $request->asText();

                return [['text' => $response->text], $response->usage];
            }

            $response = $request->asStructured();

            return [$response->structured ?? [], $response->usage];
        } catch (Throwable $exception) {
            throw AiProviderException::fromThrowable($exception);
        }
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
            ->where('status', 'pending')
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
