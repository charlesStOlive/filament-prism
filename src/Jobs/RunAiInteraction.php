<?php

namespace CharlesStOlive\FilamentPrism\Jobs;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Services\AiRunner;
use CharlesStOlive\FilamentPrism\Support\AiInteractionNotifier;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * L'appel d'une demande mise en file (`AiRunner::queue()`), puis la
 * notification de son auteur.
 *
 * Une seule tentative : relancer tout seul un appel qui a échoué, c'est
 * risquer de payer deux fois. Une demande échouée se refait à la main (« Relancer »,
 * voir `AiRunner::rerun()`). Pour la même raison, elle ne s'exécute que si elle
 * est encore `queued` : un job repris par un autre worker (un `retry_after` de
 * la queue plus court que la demande) ne rappelle pas l'IA.
 */
class RunAiInteraction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $interactionId)
    {
        $this->timeout = (int) config('filament-prism.queue.timeout', 300);
        $this->onConnection(config('filament-prism.queue.connection'));
        $this->onQueue(config('filament-prism.queue.name'));
    }

    public function handle(AiRunner $runner, AiInteractionNotifier $notifier): void
    {
        $interaction = AiInteraction::find($this->interactionId);

        if ($interaction === null || $interaction->status !== AiInteraction::STATUS_QUEUED) {
            return;
        }

        try {
            $runner->execute($interaction);
        } catch (AiProviderException) {
            // Déjà signalée (voir AiProviderException::fromThrowable()), et la demande est marquée `failed`.
        } catch (Throwable $exception) {
            report($exception);
        }

        $notifier->finished($interaction->refresh());
    }

    /** Le worker l'a arrêtée (trop longue) : la demande ne reste pas « en cours » pour toujours. */
    public function failed(?Throwable $exception): void
    {
        $interaction = AiInteraction::find($this->interactionId);

        if ($interaction === null || ! $interaction->isActive()) {
            return;
        }

        $interaction->markFailed('La demande a pris trop de temps et a été interrompue.');
        app(AiInteractionNotifier::class)->finished($interaction);
    }
}
