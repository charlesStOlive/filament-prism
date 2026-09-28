<?php

namespace CharlesStOlive\FilamentPrism\Livewire\Concerns;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Services\CorrectionService;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use Filament\Notifications\Notification;

/**
 * Le cycle d'une revue de correction (`CorrectionReview`, `GroupCorrectionReview`), selon l'état de
 * sa demande (voir `AiInteractionStatus`) :
 *
 * - brouillon : les textes qui partiront, et « Soumettre » — rien n'est payé avant ;
 * - à vérifier : le diff, « Appliquer » (propre à chaque revue), « Affiner » (avec ce qui ne va
 *   pas : la revue passe à la nouvelle version), « Ignorer » ;
 * - échec : le message, et « Relancer » ;
 * - sinon (appliquée, ignorée, affinée...) : l'issue, sans bouton.
 *
 * Chaque bouton vérifie l'état avant d'agir : un second clic parti avant le redessin ne refait rien.
 */
trait ReviewsCorrection
{
    public int $interactionId;

    /** Ce qui ne va pas, pour « Affiner ». */
    public string $feedback = '';

    public function interaction(): AiInteraction
    {
        return AiInteraction::findOrFail($this->interactionId);
    }

    public function submit(): void
    {
        $interaction = $this->interaction();

        if (! $interaction->isDraft()) {
            return;
        }

        $this->callAi(fn (CorrectionService $service) => $service->submit($interaction));
    }

    public function refine(): void
    {
        $interaction = $this->interaction();

        if (! $interaction->isStatus(AiInteraction::STATUS_PENDING)) {
            return;
        }

        if (trim($this->feedback) === '') {
            Notification::make()->warning()->title('Dites ce qui ne va pas')->body('La remarque part à l’IA avec sa correction précédente.')->send();

            return;
        }

        $this->callAi(fn (CorrectionService $service) => $service->refine($interaction, $this->feedback));
        $this->feedback = '';
    }

    public function retry(): void
    {
        $interaction = $this->interaction();

        if (! $interaction->isStatus(AiInteraction::STATUS_FAILED)) {
            return;
        }

        $this->callAi(fn (CorrectionService $service) => $service->refine($interaction, null));
    }

    public function discard(): void
    {
        $interaction = $this->interaction();

        if (! $interaction->isStatus(AiInteraction::STATUS_PENDING)) {
            return;
        }

        app(CorrectionService::class)->discard($interaction);

        Notification::make()->title('Correction ignorée')->body($this->nothingChangedMessage())->warning()->send();
    }

    /** Le message d'« Ignorer » : rien n'a été modifié. */
    abstract protected function nothingChangedMessage(): string;

    /** Une nouvelle demande (soumise, affinée, relancée) : la revue la suit. */
    protected function followed(AiInteraction $interaction): void
    {
        $this->interactionId = $interaction->getKey();
        $this->mountFor($interaction);
    }

    /** Ce qui dépend de la demande affichée (les champs cochés...). */
    abstract protected function mountFor(AiInteraction $interaction): void;

    /** @param  \Closure(CorrectionService): AiInteraction  $call */
    private function callAi(\Closure $call): void
    {
        try {
            $this->followed($call(app(CorrectionService::class)));
        } catch (AiProviderException $exception) {
            Notification::make()->danger()->title('Correction impossible')->body($exception->getMessage())->send();

            // La demande est restée, en échec : la revue la montre, avec « Relancer ».
            $latest = AiInteraction::query()->where('thread_id', $this->interaction()->thread_id)->latest('id')->first();

            if ($latest !== null) {
                $this->followed($latest);
            }
        }
    }
}
