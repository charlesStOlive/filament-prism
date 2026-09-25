<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Filament\Resources\AiInteractions\AiInteractionResource;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Services\AiRunner;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Des demandes IA et ce qu'on peut en faire : les réglages choisis, où elles
 * en sont (en file, en cours depuis N s), leur résultat, et « Accepter »
 * (`AiResource::applyResult()`), « Ignorer », « Refaire » (d'autres réglages,
 * même fil) ou « Relancer » (après un échec).
 *
 * Deux usages :
 * - un fil (`forThread()`) : une demande et toutes ses variantes, dans la page
 *   « Demandes IA » ;
 * - ce qu'on a demandé pour un modèle (`forTrackable()`, ex. un voyage), les
 *   plus récentes d'abord, dans un volet ou une modale de l'application.
 *
 * La liste se redessine toute seule toutes les 5 s tant qu'une demande est en
 * file ou en cours — le provider ne donne pas d'avancement, seulement la fin —
 * et quand une demande part d'ailleurs sur la page (`CHANGED_EVENT`).
 */
class AiInteractionList extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Une demande vient d'être faite ou d'aboutir : les listes affichées se redessinent. */
    public const CHANGED_EVENT = 'filament-prism:interactions-changed';

    #[Locked]
    public ?string $threadId = null;

    #[Locked]
    public ?string $trackableType = null;

    #[Locked]
    public int|string|null $trackableId = null;

    /** @var array<int, string> Les ressources montrées ; vide : celles mises en file (`AiResource::queued()`). */
    #[Locked]
    public array $tasks = [];

    #[Locked]
    public int $limit = 20;

    public static function forThread(string $threadId): LivewireComponent
    {
        return LivewireComponent::make(static::class, ['threadId' => $threadId])
            ->key('filament-prism-thread-'.$threadId);
    }

    /** @param  array<int, string>  $tasks */
    public static function forTrackable(Model $trackable, array $tasks = [], int $limit = 20): LivewireComponent
    {
        return LivewireComponent::make(static::class, [
            'trackableType' => $trackable->getMorphClass(),
            'trackableId' => $trackable->getKey(),
            'tasks' => $tasks,
            'limit' => $limit,
        ])->key('filament-prism-trackable-'.md5($trackable->getMorphClass().':'.$trackable->getKey()));
    }

    #[On(self::CHANGED_EVENT)]
    public function refreshList(): void {}

    /** @return Collection<int, AiInteraction> */
    public function interactions(): Collection
    {
        return AiInteraction::query()
            ->with('user')
            ->when($this->threadId !== null, fn (Builder $query) => $query->where('thread_id', $this->threadId))
            ->when($this->trackableType !== null, fn (Builder $query) => $query
                ->where('trackable_type', $this->trackableType)
                ->where('trackable_id', $this->trackableId))
            ->when($this->threadId === null, fn (Builder $query) => $query->whereIn('task', $this->taskKeys()))
            ->latest('id')
            ->limit($this->limit)
            ->get();
    }

    public function applyResultAction(): Action
    {
        return Action::make('applyResult')
            ->label(fn (array $arguments): string => $this->resourceFor($arguments)?->applyResultLabel() ?? 'Accepter')
            ->icon('heroicon-m-check')
            ->size(Size::Small)
            ->visible(fn (array $arguments): bool => ($interaction = $this->interaction($arguments)) !== null
                && $interaction->status === AiInteraction::STATUS_PENDING
                && $this->resourceFor($arguments)?->applyResultLabel() !== null)
            ->action(function (array $arguments): void {
                $interaction = $this->interaction($arguments);
                $resource = $this->resourceFor($arguments);

                if ($interaction?->status !== AiInteraction::STATUS_PENDING || $resource === null) {
                    return;
                }

                $message = $resource->applyResult($interaction);

                Notification::make()->success()->title($message ?? 'Résultat accepté')->send();
                $this->dispatch(self::CHANGED_EVENT);
            });
    }

    public function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Ignorer')
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (array $arguments): bool => $this->interaction($arguments)?->status === AiInteraction::STATUS_PENDING)
            ->action(function (array $arguments): void {
                $this->interaction($arguments)?->markDiscarded();
                $this->dispatch(self::CHANGED_EVENT);
            });
    }

    /**
     * Refaire la demande avec d'autres réglages : le formulaire de la ressource, rempli avec ceux
     * d'origine. Une ressource sans formulaire est refaite telle quelle.
     */
    public function rerunAction(): Action
    {
        return Action::make('rerun')
            ->label('Refaire')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->size(Size::Small)
            ->modalHeading(fn (array $arguments): string => 'Refaire : '.($this->resourceFor($arguments)?->label() ?? ''))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Envoyer la demande')
            ->visible(fn (array $arguments): bool => ($interaction = $this->interaction($arguments)) !== null
                && $interaction->hasResult()
                && ($this->resourceFor($arguments)?->queued() ?? false))
            ->fillForm(fn (array $arguments): array => $this->interaction($arguments)?->input ?? [])
            ->schema(fn (array $arguments): array => $this->resourceFor($arguments)?->inputSchema() ?? [])
            ->action(fn (array $arguments, array $data) => $this->rerun($arguments, $data));
    }

    public function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Relancer')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->size(Size::Small)
            ->visible(fn (array $arguments): bool => $this->interaction($arguments)?->status === AiInteraction::STATUS_FAILED
                && ($this->resourceFor($arguments)?->queued() ?? false))
            ->action(fn (array $arguments) => $this->rerun($arguments));
    }

    /** L'adresse du fil d'une demande, dans la page « Demandes IA » — quand la liste n'est pas déjà ce fil. */
    public function threadUrl(AiInteraction $interaction): ?string
    {
        if ($this->threadId !== null || ! (Filament::getCurrentPanel()?->hasPlugin(FilamentPrismPlugin::ID) ?? false)) {
            return null;
        }

        return AiInteractionResource::getUrl('view', ['record' => $interaction]);
    }

    public function hasActiveInteractions(Collection $interactions): bool
    {
        return $interactions->contains(fn (AiInteraction $interaction): bool => $interaction->isActive());
    }

    public function render(): View
    {
        $interactions = $this->interactions();
        $tasks = app(AiTaskRegistry::class);

        return view('filament-prism::livewire.ai-interaction-list', [
            'interactions' => $interactions,
            'polling' => $this->hasActiveInteractions($interactions),
            'resources' => $interactions
                ->pluck('task')
                ->unique()
                ->filter(fn (string $task): bool => $tasks->has($task))
                ->mapWithKeys(fn (string $task): array => [$task => $tasks->get($task)])
                ->filter(fn ($task): bool => $task instanceof AiResource)
                ->all(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function rerun(array $arguments, array $data = []): void
    {
        $interaction = $this->interaction($arguments);

        if ($interaction === null) {
            return;
        }

        app(AiRunner::class)->rerun($interaction, $data);

        Notification::make()->success()->title('Demande envoyée')->body('Vous serez prévenu quand elle sera prête.')->send();
        $this->dispatch(self::CHANGED_EVENT);
    }

    /** La demande désignée par les arguments d'une action — seulement parmi celles que cette liste montre. */
    private function interaction(array $arguments): ?AiInteraction
    {
        $id = $arguments['interaction'] ?? null;

        if ($id === null) {
            return null;
        }

        return AiInteraction::query()
            ->whereKey($id)
            ->when($this->threadId !== null, fn (Builder $query) => $query->where('thread_id', $this->threadId))
            ->when($this->trackableType !== null, fn (Builder $query) => $query
                ->where('trackable_type', $this->trackableType)
                ->where('trackable_id', $this->trackableId))
            ->first();
    }

    private function resourceFor(array $arguments): ?AiResource
    {
        $interaction = $this->interaction($arguments);
        $tasks = app(AiTaskRegistry::class);

        if ($interaction === null || ! $tasks->has($interaction->task)) {
            return null;
        }

        $task = $tasks->get($interaction->task);

        return $task instanceof AiResource ? $task : null;
    }

    /** @return array<int, string> */
    private function taskKeys(): array
    {
        if ($this->tasks !== []) {
            return $this->tasks;
        }

        return app(AiTaskRegistry::class)->all()
            ->filter(fn ($task): bool => $task instanceof AiResource && $task->queued())
            ->keys()
            ->all();
    }
}
