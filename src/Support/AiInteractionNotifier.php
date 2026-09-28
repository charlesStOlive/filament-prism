<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Prévient l'auteur d'une demande mise en file qu'elle est terminée — prête à
 * vérifier, ou échouée — dans les notifications Filament en base (la cloche du
 * panel). La notification mène au résultat : où la ressource le dit
 * (`AiResource::resultUrl()`), sinon dans la page « Demandes IA » du panel
 * d'où la demande est partie, s'il l'a.
 */
class AiInteractionNotifier
{
    public function __construct(private readonly AiTaskRegistry $tasks) {}

    public function finished(AiInteraction $interaction): void
    {
        $user = $interaction->user;

        if (! config('filament-prism.notify', true) || $user === null || ! $this->tasks->has($interaction->task)) {
            return;
        }

        $resource = $this->tasks->resource($interaction->task);
        $failed = $interaction->isStatus(AiInteraction::STATUS_FAILED);

        $notification = Notification::make()
            ->title($resource->label().($failed ? ' : échec' : ' : prêt'))
            ->body($failed ? $interaction->error : 'Le résultat attend votre vérification.')
            ->icon($resource->icon())
            ->status($failed ? 'danger' : 'success');

        if ($url = $this->url($interaction, $resource)) {
            $notification->actions([Action::make('view')->label('Voir')->url($url)->markAsRead()]);
        }

        $notification->sendToDatabase($user);
    }

    public function url(AiInteraction $interaction, AiResource $resource): ?string
    {
        if ($url = $resource->resultUrl($interaction)) {
            return $url;
        }

        $panelId = $interaction->meta['panel'] ?? null;

        try {
            $panel = $panelId === null ? null : Filament::getPanel($panelId, isStrict: false);
        } catch (Throwable) {
            return null;
        }

        if ($panel === null || ! $panel->hasPlugin(FilamentPrismPlugin::ID)) {
            return null;
        }

        return AiInteractionResource::getUrl('view', ['record' => $interaction], panel: $panel->getId());
    }
}
