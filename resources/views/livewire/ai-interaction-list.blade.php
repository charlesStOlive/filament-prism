{{-- Voir AiInteractionList. Classes fp-* : voir resources/css/filament-prism.css. --}}
<div class="fp-interactions" @if ($polling) wire:poll.5s @endif>
    @forelse ($interactions as $interaction)
        @php($resource = $resources[$interaction->task] ?? null)

        <article class="fp-interaction" wire:key="fp-interaction-{{ $interaction->getKey() }}">
            <header class="fp-interaction-header">
                <div class="fp-interaction-heading">
                    <x-filament::icon :icon="$resource?->icon() ?? 'heroicon-o-sparkles'" class="fp-interaction-icon" />
                    <span class="fp-interaction-title">{{ $resource?->label() ?? $interaction->task }}</span>
                    <x-filament::badge :color="\CharlesStOlive\FilamentPrism\Models\AiInteraction::statusColor($interaction->status)" size="sm">
                        {{ $interaction->statusLabel() }}
                    </x-filament::badge>
                </div>

                <div class="fp-interaction-meta">
                    <span title="{{ $interaction->created_at?->format('d/m/Y H:i') }}">{{ $interaction->created_at?->diffForHumans() }}</span>
                    @if ($interaction->user)
                        <span>· {{ $interaction->user->name }}</span>
                    @endif
                    @if (! $interaction->isActive() && $interaction->durationInSeconds() !== null)
                        <span>· {{ $interaction->durationInSeconds() }} s</span>
                    @endif
                    @if ($interaction->cost !== null)
                        <span>· {{ number_format((float) $interaction->cost, 3, ',', ' ') }} {{ config('filament-prism.currency') }}</span>
                    @endif
                    @if ($url = $this->threadUrl($interaction))
                        <a href="{{ $url }}" class="fp-link">Voir le fil</a>
                    @endif
                </div>
            </header>

            @if ($resource && ($settings = $resource->describeInput($interaction->input ?? [])) !== [])
                <ul class="fp-interaction-settings">
                    @foreach ($settings as $label => $value)
                        <li><span class="fp-muted">{{ $label }} :</span> {{ $value }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="fp-interaction-body">
                @if ($interaction->status === \CharlesStOlive\FilamentPrism\Models\AiInteraction::STATUS_QUEUED)
                    <p class="fp-interaction-progress">
                        <x-filament::loading-indicator class="fp-interaction-icon" />
                        En file d’attente…
                    </p>
                @elseif ($interaction->status === \CharlesStOlive\FilamentPrism\Models\AiInteraction::STATUS_RUNNING)
                    <p class="fp-interaction-progress">
                        <x-filament::loading-indicator class="fp-interaction-icon" />
                        En cours depuis {{ $interaction->durationInSeconds() ?? 0 }} s — vous serez prévenu quand ce sera prêt.
                    </p>
                @elseif ($interaction->status === \CharlesStOlive\FilamentPrism\Models\AiInteraction::STATUS_FAILED)
                    <p class="fp-interaction-error">{{ $interaction->error ?? 'L’appel à l’IA a échoué.' }}</p>
                @elseif ($resource)
                    {{-- Non échappé à dessein : c'est la vue du renderer de la ressource (voir AiResultRenderer). --}}
                    {!! app($resource->rendererClass())->render($interaction) !!}
                @endif
            </div>

            <footer class="fp-interaction-actions">
                {{ ($this->retryAction)(['interaction' => $interaction->getKey()]) }}
                {{ ($this->rerunAction)(['interaction' => $interaction->getKey()]) }}
                {{ ($this->discardAction)(['interaction' => $interaction->getKey()]) }}
                {{ ($this->applyResultAction)(['interaction' => $interaction->getKey()]) }}
            </footer>
        </article>
    @empty
        <p class="fp-muted">Aucune demande pour l’instant.</p>
    @endforelse

    <x-filament-actions::modals />
</div>
