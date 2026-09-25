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
                <x-filament::badge :color="$statuses::statusColor($interaction->status)" size="sm">
                    {{ $interaction->statusLabel() }}
                </x-filament::badge>
            </div>

            <div class="fp-interaction-meta">
                @if ($showsAuthor)
                    <span class="fp-interaction-author">{{ $interaction->user?->name ?? 'Utilisateur inconnu' }} ·</span>
                @endif
                <span title="{{ $interaction->created_at?->format('d/m/Y H:i') }}">{{ $interaction->created_at?->diffForHumans() }}</span>
                @if (! $interaction->isActive() && $interaction->durationInSeconds() !== null)
                    <span>· {{ $interaction->durationInSeconds() }} s</span>
                @endif
                @if ($interaction->total_tokens > 0)
                    <span>· {{ number_format($interaction->total_tokens, 0, ',', ' ') }} tokens</span>
                @endif
                @if ($interaction->cost_eur !== null)
                    <span title="Coût estimé : tokens × prix du catalogue">· ≈ {{ number_format((float) $interaction->cost_eur, 3, ',', ' ') }} €</span>
                @elseif ($interaction->cost !== null)
                    <span title="Coût estimé : tokens × prix du catalogue">· ≈ {{ number_format((float) $interaction->cost, 3, ',', ' ') }} {{ $interaction->currency }}</span>
                @endif
                @if ($url = $this->threadUrl())
                    <a href="{{ $url }}" class="fp-link">Voir le fil</a>
                @endif
            </div>
        </header>

        {{ $this->settingsInfolist }}

        @if ($interaction->status === $statuses::STATUS_QUEUED)
            <p class="fp-interaction-progress">
                <x-filament::loading-indicator class="fp-interaction-icon" />
                En file d’attente…
            </p>
        @elseif ($interaction->status === $statuses::STATUS_RUNNING)
            <p class="fp-interaction-progress">
                <x-filament::loading-indicator class="fp-interaction-icon" />
                En cours depuis {{ $interaction->durationInSeconds() ?? 0 }} s — vous serez prévenu quand ce sera prêt.
            </p>
        @elseif ($interaction->status === $statuses::STATUS_FAILED)
            <p class="fp-interaction-error">{{ $interaction->error ?? 'L’appel à l’IA a échoué.' }}</p>
        @else
            {{ $this->resultInfolist }}
        @endif

        <footer class="fp-interaction-actions">
            {{ $this->retryAction }}
            {{ $this->rerunAction }}
            {{ $this->discardAction }}
            {{ $this->applyResultAction }}
        </footer>
    @endif

    <x-filament-actions::modals />
</article>
