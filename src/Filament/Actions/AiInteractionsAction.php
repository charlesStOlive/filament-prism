<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Livewire\AiInteractionList;
use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Les demandes IA (`AiInteractionList`) dans une modale — ou, avec
 * `->slideOver()`, dans un volet qui glisse par-dessus la page. Le troisième
 * affichage, un volet posé à côté du formulaire, est `AiInteractionsSidePane`.
 *
 *     AiInteractionsAction::make()                                   // toutes mes demandes, en modale
 *     AiInteractionsAction::make()->trackable($this->record)->slideOver()
 *     AiInteractionsAction::make()->tasks(['photo-sketch'])
 *
 * Le bouton porte le nombre de demandes en cours, s'il y en a.
 */
class AiInteractionsAction extends Action
{
    protected Model|Closure|null $trackable = null;

    /** @var array<int, string>|Closure */
    protected array|Closure $tasks = [];

    public static function getDefaultName(): ?string
    {
        return 'aiInteractions';
    }

    /** Seulement ce qui a été demandé pour ce modèle (ex. un voyage). */
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

    public function getTrackable(): ?Model
    {
        return $this->evaluate($this->trackable);
    }

    /** @return array<int, string> */
    public function getTasks(): array
    {
        return (array) $this->evaluate($this->tasks);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Demandes IA')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->badge(fn (): ?int => $this->activeCount() ?: null)
            ->badgeColor('info')
            ->modalHeading('Demandes IA')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->schema(fn (): array => [AiInteractionList::make($this->getTrackable(), $this->getTasks())]);
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
