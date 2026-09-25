<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Services\AiRunner;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
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
 * `AiResultSchema`), et ce qu'on peut en faire — « Accepter »
 * (`AiResource::applyResult()`), « Ignorer », « Refaire » (d'autres réglages,
 * même fil), « Relancer » (après un échec).
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

    public function applyResultAction(): Action
    {
        return Action::make('applyResult')
            ->label(fn (): string => $this->resource()?->applyResultLabel() ?? 'Accepter')
            ->icon('heroicon-m-check')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->status === AiInteraction::STATUS_PENDING
                && $this->resource()?->applyResultLabel() !== null)
            ->action(function (): void {
                $interaction = $this->interaction();
                $resource = $this->resource();

                if ($interaction?->status !== AiInteraction::STATUS_PENDING || $resource === null) {
                    return;
                }

                $message = $resource->applyResult($interaction);

                Notification::make()->success()->title($message ?? 'Résultat accepté')->send();
                $this->changed();
            });
    }

    public function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Ignorer')
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->status === AiInteraction::STATUS_PENDING)
            ->action(function (): void {
                $this->interaction()?->markDiscarded();
                $this->changed();
            });
    }

    /**
     * Refaire la demande avec d'autres réglages : le formulaire de la ressource, rempli avec ceux
     * d'origine. Ce que le formulaire ne montre pas (ex. les photos choisies) reste celui d'origine.
     */
    public function rerunAction(): Action
    {
        return Action::make('rerun')
            ->label('Refaire')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading(fn (): string => 'Refaire : '.($this->resource()?->label() ?? ''))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Envoyer la demande')
            ->visible(fn (): bool => ($this->interaction()?->hasResult() ?? false) && ($this->resource()?->queued() ?? false))
            ->fillForm(fn (): array => $this->interaction()?->input ?? [])
            ->schema(fn (): array => $this->resource()?->inputSchema() ?? [])
            ->action(fn (array $data) => $this->rerun($data));
    }

    public function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Relancer')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (): bool => $this->interaction()?->status === AiInteraction::STATUS_FAILED && ($this->resource()?->queued() ?? false))
            ->action(fn () => $this->rerun());
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

    /** @param  array<string, mixed>  $data */
    private function rerun(array $data = []): void
    {
        $interaction = $this->interaction();

        if ($interaction === null) {
            return;
        }

        app(AiRunner::class)->rerun($interaction, $data);

        Notification::make()->success()->title('Demande envoyée')->body('Vous serez prévenu quand elle sera prête.')->send();
        $this->changed();
    }

    /** La demande a changé : on la relit, et les listes affichées se redessinent. */
    private function changed(): void
    {
        $this->cachedInteraction = null;
        $this->dispatch(AiInteractionList::CHANGED_EVENT);
    }
}
