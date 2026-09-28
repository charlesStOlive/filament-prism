{{-- Voir AiInteractionCard. Classes fp-* : voir resources/css/filament-prism.css. --}}
@php($statuses = \CharlesStOlive\FilamentPrism\Models\AiInteraction::class)
<article class="fp-interaction" @if ($interaction?->isActive()) wire:poll.5s @endif>
    @if ($interaction === null)
        <p class="fp-muted">Cette demande n’existe plus.</p>
    @else
        <header class="fp-interaction-header">
            <div class="fp-interaction-heading">
                <x-filament::icon :icon="$resource?->icon() ?? 'heroicon-o-sparkles'" class="fp-interaction-icon" />
                <span class="fp-interaction-title">{{ $resource?->label() ?? $interaction->task }}</span>
                <x-filament::badge :color="$interaction->status->getColor()" size="sm" :tooltip="$interaction->status->getDescription()">
                    {{ $interaction->statusLabel() }}
                </x-filament::badge>
            </div>

            <div class="fp-interaction-meta">
                @if ($showsAuthor)
                    <span class="fp-interaction-author">{{ $interaction->user?->name ?? 'Utilisateur inconnu' }} ·</span>
                @endif
                <span title="{{ $interaction->created_at?->timezone(\Filament\Support\Facades\FilamentTimezone::get())->format('d/m/Y H:i') }}">{{ $interaction->created_at?->diffForHumans() }}</span>
                @if (! $interaction->isActive() && $interaction->durationInSeconds() !== null)
                    <span>· {{ $interaction->durationInSeconds() }} s</span>
                @endif
                @if ($interaction->total_tokens > 0)
                    <span>· {{ number_format($interaction->total_tokens, 0, ',', ' ') }} tokens</span>
                @endif
                @if ($interaction->cost_eur !== null)
                    <span title="Coût estimé : tokens × prix du catalogue">· ≈ {{ \CharlesStOlive\FilamentPrism\Support\AiMoney::format((float) $interaction->cost_eur) }}</span>
                @elseif ($interaction->cost !== null)
                    <span title="Coût estimé : tokens × prix du catalogue">· ≈ {{ \CharlesStOlive\FilamentPrism\Support\AiMoney::format((float) $interaction->cost, (string) $interaction->currency) }}</span>
                @endif
                @if ($url = $this->threadUrl())
                    <a href="{{ $url }}" class="fp-link">Voir le fil</a>
                @endif
            </div>
        </header>

        @if ($feedback = $interaction->feedback())
            <p class="fp-interaction-feedback">
                <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="fp-interaction-icon" />
                <span><strong>Ce qui n’allait pas :</strong> {{ $feedback }}</span>
            </p>
        @endif

        {{ $this->settingsInfolist }}

        @if ($interaction->isDraft())
            <p class="fp-interaction-progress">
                <x-filament::icon icon="heroicon-m-pencil-square" class="fp-interaction-icon" />
                Brouillon — rien n’a encore été envoyé à l’IA.
            </p>
        @elseif ($interaction->isStatus($statuses::STATUS_QUEUED))
            <p class="fp-interaction-progress">
                <x-filament::loading-indicator class="fp-interaction-icon" />
                En file d’attente…
            </p>
        @elseif ($interaction->isStatus($statuses::STATUS_RUNNING))
            <p class="fp-interaction-progress">
                <x-filament::loading-indicator class="fp-interaction-icon" />
                En cours depuis {{ $interaction->durationInSeconds() ?? 0 }} s — vous serez prévenu quand ce sera prêt.
            </p>
        @elseif (! $interaction->hasResult())
            <p class="fp-interaction-error">{{ $interaction->error ?? 'L’appel à l’IA a échoué.' }}</p>
        @else
            {{ $this->resultInfolist }}
        @endif

        {{-- Une action posée à la main se dessine même masquée (grisée) : seules les visibles sont posées (voir footerActions()). --}}
        @php($footer = $this->footerActions())
        @if ($footer['primary'] !== [] || $footer['others'] !== [])
            <footer class="fp-interaction-actions">
                @if ($footer['others'] !== [])
                    {{ \Filament\Actions\ActionGroup::make($footer['others'])
                        ->icon('heroicon-m-ellipsis-horizontal')
                        ->tooltip('Autres actions')
                        ->color('gray')
                        ->size(\Filament\Support\Enums\Size::Small)
                        ->iconButton() }}
                @endif

                @foreach ($footer['primary'] as $action)
                    {{ $action }}
                @endforeach
            </footer>
        @endif
    @endif

    <x-filament-actions::modals />
</article>
