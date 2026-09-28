<?php

namespace CharlesStOlive\FilamentPrism\States\Transitions;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\States\Queued;
use CharlesStOlive\FilamentPrism\States\Running;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Spatie\ModelStates\Transition;

/**
 * Un brouillon part à l'IA : en file (`queued`) pour une ressource en arrière-plan, en cours
 * (`running`) pour un appel fait tout de suite. L'appel lui-même est celui d'`AiRunner::submit()` —
 * la transition ne fait que changer l'état, avec ce qu'`AiRunner` y écrit (`started_at`...).
 */
class Submit extends Transition implements HasColor, HasDescription, HasIcon, HasLabel
{
    /** @param  class-string<Queued|Running>|null  $to */
    public function __construct(private AiInteraction $interaction, private ?string $to = null) {}

    public function handle(): AiInteraction
    {
        $this->interaction->forceFill(['status' => $this->to ?? Queued::class])->save();

        return $this->interaction;
    }

    public function getLabel(): string
    {
        return 'Soumettre';
    }

    public function getColor(): string
    {
        return 'primary';
    }

    public function getIcon(): string
    {
        return 'heroicon-m-paper-airplane';
    }

    public function getDescription(): string
    {
        return 'Envoyer la demande à l’IA.';
    }
}
