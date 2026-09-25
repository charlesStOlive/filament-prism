<?php

namespace CharlesStOlive\FilamentPrism\Filament\Actions;

use CharlesStOlive\FilamentPrism\Livewire\AiInteractionList;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use CharlesStOlive\FilamentPrism\Services\AiRunner;
use CharlesStOlive\FilamentPrism\Support\AiProviderException;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Le cycle complet d'une `AiResource`, dans une modale en deux étapes :
 *
 * 1. son formulaire d'entrée (`inputSchema()`) — à la validation de l'étape,
 *    `handleInput()` lance le ou les appels et prépare la réception ;
 * 2. sa réception (`receptionSchema()`), que l'utilisateur vérifie — la
 *    soumission de la modale la passe à `apply()`.
 *
 * Sans formulaire d'entrée, l'appel part à l'ouverture de la modale et seule
 * la réception s'affiche.
 *
 * Une ressource mise en file (`AiResource::queued()`) n'a pas de deuxième
 * étape : la validation du formulaire enregistre la demande, un job fait
 * l'appel, et la réception se fait plus tard, depuis « Demandes IA » (voir
 * `AiInteractionList`) — la modale se ferme tout de suite.
 *
 *     AiResourceAction::make('createFromFiles')->aiResource('supplier-invoice-extraction')
 *
 * `aiResource()` et non `resource()` : une méthode d'un nom déjà porté par
 * `Filament\Actions\Action` (ou l'un de ses traits) avec une autre signature
 * est une erreur fatale, détectée seulement au premier rendu (voir le README,
 * `GroupCorrectionAction::subjects()`).
 */
class AiResourceAction extends Action
{
    protected string|Closure|null $aiResourceKey = null;

    protected Model|Closure|null $trackable = null;

    public static function getDefaultName(): ?string
    {
        return 'aiResource';
    }

    public function aiResource(string|Closure $key): static
    {
        $this->aiResourceKey = $key;

        return $this;
    }

    /** Le périmètre de la demande pour les stats et les listes de demandes (ex. le voyage). */
    public function trackable(Model|Closure|null $trackable): static
    {
        $this->trackable = $trackable;

        return $this;
    }

    public function getTrackable(): ?Model
    {
        return $this->evaluate($this->trackable);
    }

    public function getAiResource(): AiResource
    {
        $key = $this->evaluate($this->aiResourceKey);

        if (! is_string($key)) {
            throw new RuntimeException('AiResourceAction::aiResource() doit recevoir la clé d’une AiResource enregistrée.');
        }

        return app(AiTaskRegistry::class)->resource($key);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(fn (): string => $this->getAiResource()->label())
            ->icon('heroicon-o-sparkles')
            ->modalHeading(fn (): string => $this->getAiResource()->label())
            ->modalWidth(Width::FiveExtraLarge)
            ->disabled(fn (): bool => ! $this->isProviderConfigured())
            ->tooltip(fn (): ?string => $this->isProviderConfigured() ? null : 'Clé API manquante pour ce provider — voir le fichier .env.')
            ->fillForm(fn (): array => $this->hasInputStep() || $this->isQueued() ? [] : ['reception' => $this->handleInput([])])
            ->modalSubmitActionLabel(fn (): ?string => $this->isQueued() ? 'Envoyer la demande' : null)
            ->schema(fn (): array => $this->isQueued() ? [
                Group::make($this->getAiResource()->inputSchema() ?? [])->statePath('input'),
            ] : ($this->hasInputStep() ? [
                Step::make('input')
                    ->label('Données')
                    ->schema([Group::make($this->getAiResource()->inputSchema())->statePath('input')])
                    ->afterValidation(fn (Get $get, Set $set) => $set('reception', $this->handleInput($get('input') ?? []))),
                Step::make('reception')
                    ->label('Vérification')
                    ->schema([$this->receptionGroup()]),
            ] : [$this->receptionGroup()]))
            ->action(fn (array $data) => $this->isQueued()
                ? $this->queue($data['input'] ?? [])
                : $this->getAiResource()->apply($data['reception'] ?? []));
    }

    /** Un wizard seulement quand la ressource a un formulaire d'entrée — connu une fois `aiResource()` posé, pas au `setUp()`. */
    public function isWizard(): bool
    {
        return $this->hasInputStep() && ! $this->isQueued();
    }

    protected function isQueued(): bool
    {
        return $this->getAiResource()->queued();
    }

    /** @param  array<string, mixed>  $input */
    protected function queue(array $input): void
    {
        app(AiRunner::class)->queue($this->getAiResource(), $input, trackable: $this->getTrackable());

        Notification::make()->success()->title('Demande envoyée')->body('Vous serez prévenu quand elle sera prête.')->send();
        $this->getLivewire()->dispatch(AiInteractionList::CHANGED_EVENT);
    }

    protected function hasInputStep(): bool
    {
        return $this->getAiResource()->inputSchema() !== null;
    }

    protected function receptionGroup(): Group
    {
        return Group::make($this->getAiResource()->receptionSchema() ?? [])->statePath('reception');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function handleInput(array $data): array
    {
        try {
            return $this->getAiResource()->handleInput($data);
        } catch (AiProviderException $exception) {
            Notification::make()->danger()->title('Appel à l’IA impossible')->body($exception->getMessage())->send();

            throw new Halt;
        }
    }

    protected function isProviderConfigured(): bool
    {
        return app(AiRunner::class)->providerIsConfigured($this->getAiResource()->provider());
    }
}
