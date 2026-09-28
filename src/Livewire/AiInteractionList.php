<?php

namespace CharlesStOlive\FilamentPrism\Livewire;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Support\AiAccess;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Des demandes IA, les plus récentes d'abord, une carte chacune
 * (`AiInteractionCard`). Le même composant partout, quel que soit l'endroit
 * où on l'ouvre :
 *
 * - en modale ou en slide-over : `AiInteractionsAction` ;
 * - dans le volet latéral de filament-ui : `AiInteractionsSidePane` ;
 * - dans la page « Demandes IA » : le fil d'une demande (`forThread()`).
 *
 * Chacun n'y voit que ses demandes ; qui peut tout voir y voit celles de tout
 * le monde, avec leur auteur (voir `AiAccess`). `$trackable` restreint à ce
 * qui a été demandé pour un modèle (ex. un voyage), `$tasks` à certaines
 * ressources — sans l'un ni l'autre : toutes ses demandes. Une demande
 * archivée quitte les listes d'un modèle, pas les listes globales ni son fil.
 *
 * La liste se redessine quand une demande part ou aboutit sur la page
 * (`CHANGED_EVENT`) ; chaque carte suit seule sa demande en cours.
 */
class AiInteractionList extends Component
{
    /** Une demande vient d'être faite ou d'aboutir : les listes affichées se redessinent. */
    public const CHANGED_EVENT = 'filament-prism:interactions-changed';

    /** Des cartes bordées, pour une liste posée seule sur une page (le fil d'une demande). */
    public const LAYOUT_CARDS = 'cards';

    /** Des lignes séparées d'un filet, pour un contenant qui a déjà son cadre et ses marges (modale, slide-over). */
    public const LAYOUT_ROWS = 'rows';

    /** Des lignes avec leurs propres marges, pour le volet latéral, dont le contenu touche le cadre. */
    public const LAYOUT_PANE = 'pane';

    #[Locked]
    public ?string $threadId = null;

    #[Locked]
    public ?string $trackableType = null;

    #[Locked]
    public int|string|null $trackableId = null;

    /** @var array<int, string> Les ressources montrées ; vide : toutes. */
    #[Locked]
    public array $tasks = [];

    #[Locked]
    public int $limit = 30;

    #[Locked]
    public string $layout = self::LAYOUT_CARDS;

    /** @param  array<int, string>  $tasks */
    public static function make(?Model $trackable = null, array $tasks = [], int $limit = 30, string $layout = self::LAYOUT_CARDS): LivewireComponent
    {
        $scope = $trackable === null ? 'all' : $trackable->getMorphClass().':'.$trackable->getKey();

        return LivewireComponent::make(static::class, [
            'trackableType' => $trackable?->getMorphClass(),
            'trackableId' => $trackable?->getKey(),
            'tasks' => $tasks,
            'limit' => $limit,
            'layout' => $layout,
        ])->key('filament-prism-interactions-'.md5($scope.'|'.implode(',', $tasks).'|'.$layout));
    }

    /** @param  array<int, string>  $tasks */
    public static function forTrackable(Model $trackable, array $tasks = [], int $limit = 30, string $layout = self::LAYOUT_CARDS): LivewireComponent
    {
        return static::make($trackable, $tasks, $limit, $layout);
    }

    public static function forThread(string $threadId): LivewireComponent
    {
        return LivewireComponent::make(static::class, ['threadId' => $threadId])
            ->key('filament-prism-thread-'.$threadId);
    }

    /**
     * Les demandes que cette liste montre, à l'utilisateur courant.
     *
     * @param  array<int, string>  $tasks
     */
    public static function query(?string $threadId = null, ?string $trackableType = null, int|string|null $trackableId = null, array $tasks = []): Builder
    {
        return AiAccess::scope(AiInteraction::query())
            ->when($threadId !== null, fn (Builder $query) => $query->where('thread_id', $threadId))
            // Les demandes d'un modèle : sans celles qu'on a archivées, qui ne restent que dans les listes globales.
            ->when($trackableType !== null, fn (Builder $query) => $query
                ->where('trackable_type', $trackableType)
                ->where('trackable_id', $trackableId)
                ->notArchived())
            ->when($tasks !== [], fn (Builder $query) => $query->whereIn('task', $tasks));
    }

    #[On(self::CHANGED_EVENT)]
    public function refreshList(): void {}

    public function render(): View
    {
        return view('filament-prism::livewire.ai-interaction-list', [
            'interactionIds' => static::query($this->threadId, $this->trackableType, $this->trackableId, $this->tasks)
                ->latest('id')
                ->limit($this->limit)
                ->pluck('id')
                ->all(),
        ]);
    }
}
