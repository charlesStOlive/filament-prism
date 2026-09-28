<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Services\AiRunner;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use CharlesStOlive\FilamentPrism\Support\AiResultSchema;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Une demande IA : de qui (pour qui voit tout), où elle en est, ses réglages
 * et son résultat (des composants Filament que la ressource déclare, voir
 * `AiResultSchema`), et ce qu'on peut en faire selon son état (voir
 * `AiInteractionStatus`) — une action par transition :
 *
 * - brouillon : « Soumettre », « Modifier », « Supprimer le brouillon » ;
 * - à vérifier : « Accepter » (`AiResource::accept()`, qui peut aussi
 *   appliquer le résultat), « Affiner », « Ignorer » ;
 * - acceptée ou ignorée : « Affiner », « Archiver » ; échec : « Relancer » ;
 * - archivée : « Désarchiver ».
 *
 * L'étape naturelle est un bouton (`primaryActions()`), le reste un menu.
 *
 * Posée par `AiInteractionList`, une par demande. Elle se redessine seule
 * toutes les 5 s tant que la demande est en file ou en cours — le provider ne
 * donne pas d'avancement, seulement la fin. On n'y voit qu'une demande
 * qu'on a le droit de voir (voir `AiAccess`).
 */
class AiInteractionCard extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public int $interactionId;

    /** Un lien vers le fil de la demande (« Demandes IA »), quand la liste n'est pas déjà ce fil. */
    #[Locked]
    public bool $linksToThread = true;

    private ?AiInteraction $cachedInteraction = null;

    public function interaction(): ?AiInteraction
    {
        return $this->cachedInteraction ??= AiAccess::scope(AiInteraction::query())
            ->with('user')
            ->find($this->interactionId);
    }

    public function resource(): ?AiResource
    {
        $interaction = $this->interaction();
        $tasks = app(AiTaskRegistry::class);
        $task = $interaction !== null && $tasks->has($interaction->task) ? $tasks->get($interaction->task) : null;

        return $task instanceof AiResource ? $task : null;
    }

    public function settingsInfolist(Schema $schema): Schema
    {
        $interaction = $this->interaction();

        return $schema
            ->record($interaction)
            ->components($interaction === null ? [] : AiResultSchema::input($interaction));
    }

    public function resultInfolist(Schema $schema): Schema
    {
        $interaction = $this->interaction();

        return $schema
            ->record($interaction)
            ->components($interaction?->hasResult() ? AiResultSchema::result($interaction) : []);
    }

    public function submitAction(): Action
    {
        return Action::make('submit')
            ->label('Soumettre')
            ->icon('heroicon-m-paper-airplane')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->isDraft() ?? false)
            ->action(fn () => $this->send(fn (AiRunner $runner, AiInteraction $interaction) => $runner->submit($interaction)));
    }

    /** Changer les réglages d'un brouillon, avant de l'envoyer. */
    public function editDraftAction(): Action
    {
        return Action::make('editDraft')
            ->label('Modifier')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading(fn (): string => 'Modifier : '.($this->resource()?->label() ?? ''))
            ->modalWidth(Width::ThreeExtraLarge)
            ->visible(fn (): bool => ($this->interaction()?->isDraft() ?? false) && $this->resource()?->inputSchema() !== null)
            ->fillForm(fn (): array => $this->interaction()?->input ?? [])
            ->schema(fn (): array => $this->resource()?->inputSchema() ?? [])
            ->action(function (array $data): void {
                $interaction = $this->interaction();

                if (! $interaction?->isDraft()) {
                    return;
                }

                unset($data['preview']);
                $interaction->forceFill(['input' => array_replace($interaction->input ?? [], $data)])->save();
                $this->changed();
            });
    }

    /** Rien n'a été payé : un brouillon abandonné s'efface pour de bon. */
    public function deleteDraftAction(): Action
    {
        return Action::make('deleteDraft')
            ->label('Supprimer le brouillon')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->interaction()?->isDraft() ?? false)
            ->action(function (): void {
                if ($this->interaction()?->isDraft()) {
                    $this->interaction()->delete();
                }

                $this->changed();
            });
    }

    /**
     * Toute demande à vérifier s'accepte ; une ressource qui sait appliquer son résultat le fait en
     * même temps (`AiResource::accept()`), et le bouton porte son libellé.
     */
    public function acceptAction(): Action
    {
        return Action::make('accept')
            ->label(fn (): string => $this->resource()?->acceptLabel() ?? 'Accepter')
            ->icon('heroicon-m-check')
            ->size(Size::Small)
            ->modalWidth(Width::ThreeExtraLarge)
            ->visible(function (): bool {
                $interaction = $this->interaction();

                return ($interaction?->isStatus(AiInteraction::STATUS_PENDING) ?? false)
                    && ($this->resource()?->canAccept($interaction) ?? false);
            })
            ->schema(fn (): ?array => ($interaction = $this->interaction()) === null ? null : $this->resource()?->acceptSchema($interaction))
            ->action(function (array $data): void {
                $interaction = $this->interaction();

                if (! $interaction?->isStatus(AiInteraction::STATUS_PENDING) || ($resource = $this->resource()) === null) {
                    return;
                }

                $message = $resource->accept($interaction, $data);

                Notification::make()->success()->title($message ?? 'Résultat accepté')->send();
                $this->changed();
            });
    }

    /**
     * Une nouvelle version, dans le même fil : d'autres réglages et/ou ce qui ne va pas. Ce que le
     * formulaire ne montre pas (ex. les photos choisies) reste celui d'origine.
     */
    public function refineAction(): Action
    {
        return Action::make('refine')
            ->label('Affiner')
            ->icon('heroicon-m-adjustments-horizontal')
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading(fn (): string => 'Affiner : '.($this->resource()?->label() ?? ''))
            ->modalDescription('Une nouvelle version part à l’IA, avec sa réponse précédente et ce qui ne va pas.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Soumettre')
            ->visible(function (): bool {
                $interaction = $this->interaction();

                return ($interaction?->isStatus(AiInteraction::STATUS_PENDING, AiInteraction::STATUS_ACCEPTED, AiInteraction::STATUS_DISCARDED) ?? false)
                    && ($this->resource()?->canRefine($interaction) ?? false);
            })
            ->fillForm(fn (): array => $this->interaction()?->input ?? [])
            ->schema(fn (): array => ($interaction = $this->interaction()) === null ? [] : ($this->resource()?->refineSchema($interaction) ?? []))
            ->action(function (array $data): void {
                $feedback = $data['feedback'] ?? null;
                unset($data['feedback'], $data['preview']);

                $this->send(fn (AiRunner $runner, AiInteraction $interaction) => $runner->refine($interaction, $feedback, $data));
            });
    }

    public function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Ignorer')
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->isStatus(AiInteraction::STATUS_PENDING) ?? false)
            ->action(function (): void {
                $this->interaction()?->markDiscarded();
                $this->changed();
            });
    }

    /** Après un échec : la même demande, telle quelle, en nouvelle version du fil. */
    public function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Relancer')
            ->icon('heroicon-m-arrow-path')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->isStatus(AiInteraction::STATUS_FAILED) ?? false)
            ->action(fn () => $this->send(fn (AiRunner $runner, AiInteraction $interaction) => $runner->refine($interaction)));
    }

    /** Une demande terminée quitte la liste de son modèle ; elle reste dans les listes globales. */
    public function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archiver')
            ->icon('heroicon-m-archive-box-arrow-down')
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->canBeArchived() ?? false)
            ->action(function (): void {
                $this->interaction()?->archive();

                Notification::make()->success()->title('Demande archivée')->body('Elle reste dans « Demandes IA ».')->send();
                $this->changed();
            });
    }

    public function unarchiveAction(): Action
    {
        return Action::make('unarchive')
            ->label('Désarchiver')
            ->icon('heroicon-m-archive-box-x-mark')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->isArchived() ?? false)
            ->action(function (): void {
                $this->interaction()?->unarchive();
                $this->changed();
            });
    }

    /**
     * Les actions visibles de la demande, dans l'ordre du cycle : l'étape naturelle (soumettre,
     * accepter, relancer, désarchiver) en bouton, le reste dans un menu.
     *
     * @return array{primary: array<int, Action>, others: array<int, Action>}
     */
    public function footerActions(): array
    {
        $visible = fn (array $actions): array => array_values(array_filter($actions, fn (Action $action): bool => $action->isVisible()));

        return [
            'primary' => $visible([$this->submitAction, $this->acceptAction, $this->retryAction, $this->unarchiveAction]),
            'others' => $visible([$this->editDraftAction, $this->refineAction, $this->discardAction, $this->archiveAction, $this->deleteDraftAction]),
        ];
    }

    public function threadUrl(): ?string
    {
        $interaction = $this->interaction();

        if (! $this->linksToThread || $interaction === null || ! (Filament::getCurrentPanel()?->hasPlugin(FilamentPrismPlugin::ID) ?? false)) {
            return null;
        }

        return AiInteractionResource::getUrl('view', ['record' => $interaction]);
    }

    public function render(): View
    {
        return view('filament-prism::livewire.ai-interaction-card', [
            'interaction' => $this->interaction(),
            'resource' => $this->resource(),
            'showsAuthor' => AiAccess::canSeeAll(),
        ]);
    }

    /**
     * Envoie à l'IA (soumettre, affiner, relancer) : tout de suite pour une ressource synchrone —
     * l'appel peut échouer, son message est montré —, en file sinon.
     *
     * @param  \Closure(AiRunner, AiInteraction): AiInteraction  $send
     */
    private function send(\Closure $send): void
    {
        $interaction = $this->interaction();

        if ($interaction === null) {
            return;
        }

        try {
            $sent = $send(app(AiRunner::class), $interaction);
        } catch (AiProviderException $exception) {
            Notification::make()->danger()->title('Appel à l’IA impossible')->body($exception->getMessage())->send();
            $this->changed();

            return;
        }

        $sent->isActive()
            ? Notification::make()->success()->title('Demande envoyée')->body('Vous serez prévenu quand elle sera prête.')->send()
            : Notification::make()->success()->title('Réponse reçue')->send();

        $this->changed();
    }

    /** La demande a changé : on la relit, et les listes affichées se redessinent. */
    private function changed(): void
    {
        $this->cachedInteraction = null;
        $this->dispatch(AiInteractionList::CHANGED_EVENT);
    }
}
