<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Enums\AiInteractionsDisplay;
use CharlesStOlive\FilamentPrism\Filament\SidePane\AiInteractionsSidePane;
use CharlesStOlive\FilamentPrism\FilamentPrismPlugin;
use CharlesStOlive\FilamentPrism\Livewire\AiInteractionList;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentUi\Split\SidePane;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Le bouton « Demandes IA » : les demandes (`AiInteractionList`) là où on les
 * veut (`display()`) — une modale, un slide-over, ou le volet latéral de
 * filament-ui, à côté du formulaire. Sans `display()`, le réglage du panel
 * (`FilamentPrismPlugin::interactionsDisplay()`), sinon une modale.
 *
 *     AiInteractionsAction::make()                                   // toutes mes demandes
 *     AiInteractionsAction::make()->trackable($this->record)->tasks(['photo-sketch'])
 *         ->display(AiInteractionsDisplay::SlideOver)
 *
 * En volet, la page doit avoir des volets (`HasSidePane` de filament-ui) et y
 * déclarer celui-ci — la même action le construit, pour que le bouton et le
 * volet montrent les mêmes demandes :
 *
 *     protected function getSidePanes(): array
 *     {
 *         return [AiInteractionsSidePane::NAME => $this->aiInteractionsAction()->toSidePane()];
 *     }
 *
 * Une page sans volets ouvre alors un slide-over. Le bouton porte le nombre de
 * demandes en cours, s'il y en a.
 */
class AiInteractionsAction extends Action
{
    protected Model|Closure|null $trackable = null;

    /** @var array<int, string>|Closure */
    protected array|Closure $tasks = [];

    protected AiInteractionsDisplay|Closure|null $display = null;

    public static function getDefaultName(): ?string
    {
        return 'aiInteractions';
    }

    /** Seulement ce qui a été demandé pour ce modèle (ex. un voyage), sans ce qu'on a archivé. */
    public function trackable(Model|Closure|null $trackable): static
    {
        $this->trackable = $trackable;

        return $this;
    }

    /** @param  array<int, string>|Closure  $tasks  Seulement ces ressources IA (leurs clés). */
    public function tasks(array|Closure $tasks): static
    {
        $this->tasks = $tasks;

        return $this;
    }

    public function display(AiInteractionsDisplay|Closure|null $display): static
    {
        $this->display = $display;

        return $this;
    }

    public function getTrackable(): ?Model
    {
        return $this->evaluate($this->trackable);
    }

    /** @return array<int, string> */
    public function getTasks(): array
    {
        return (array) $this->evaluate($this->tasks);
    }

    public function getDisplay(): AiInteractionsDisplay
    {
        $display = $this->evaluate($this->display);

        if ($display instanceof AiInteractionsDisplay) {
            return $display;
        }

        $panel = filament()->getCurrentPanel();

        return $panel?->hasPlugin(FilamentPrismPlugin::ID)
            ? $panel->getPlugin(FilamentPrismPlugin::ID)->getInteractionsDisplay()
            : AiInteractionsDisplay::Modal;
    }

    /** Le volet de ces demandes, à déclarer dans `getSidePanes()` de la page (voir la docblock de la classe). */
    public function toSidePane(): SidePane
    {
        return AiInteractionsSidePane::make($this->getTrackable(), $this->getTasks());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Demandes IA')
            ->icon('heroicon-o-sparkles')
            ->color(fn (): string => $this->isSidePaneOpen() ? 'primary' : 'gray')
            ->badge(fn (): ?int => $this->activeCount() ?: null)
            ->badgeColor('info')
            ->modalHeading('Demandes IA')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->slideOver(fn (): bool => $this->opensSlideOver())
            ->modalHidden(fn (): bool => $this->opensSidePane())
            ->schema(fn (): array => $this->opensSidePane() ? [] : [
                AiInteractionList::make($this->getTrackable(), $this->getTasks(), layout: AiInteractionList::LAYOUT_ROWS),
            ])
            ->action(function (): void {
                if ($this->opensSidePane()) {
                    $this->getLivewire()->toggleSidePane(AiInteractionsSidePane::NAME);
                }
            });
    }

    /** En volet, si la page en a (filament-ui) ; une page qui n'en a pas ouvre un slide-over. */
    protected function opensSidePane(): bool
    {
        return $this->getDisplay() === AiInteractionsDisplay::SidePane && $this->pageHasSidePanes();
    }

    protected function opensSlideOver(): bool
    {
        return match ($this->getDisplay()) {
            AiInteractionsDisplay::SlideOver => true,
            AiInteractionsDisplay::SidePane => ! $this->pageHasSidePanes(),
            AiInteractionsDisplay::Modal => false,
        };
    }

    protected function pageHasSidePanes(): bool
    {
        return method_exists($this->getLivewire(), 'toggleSidePane');
    }

    /** Le bouton prend la couleur primaire tant que son volet est ouvert, comme les boutons de volet de filament-ui. */
    protected function isSidePaneOpen(): bool
    {
        return $this->opensSidePane() && $this->getLivewire()->isSidePaneOpen(AiInteractionsSidePane::NAME);
    }

    protected function activeCount(): int
    {
        $trackable = $this->getTrackable();

        return AiInteractionList::query(
            trackableType: $trackable?->getMorphClass(),
            trackableId: $trackable?->getKey(),
            tasks: $this->getTasks(),
        )->whereIn('status', AiInteraction::ACTIVE_STATUSES)->count();
    }
}
