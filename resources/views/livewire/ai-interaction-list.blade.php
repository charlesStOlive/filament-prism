{{-- Voir AiInteractionList : une carte par demande (AiInteractionCard). --}}
<div class="fp-interactions" data-layout="{{ $layout }}">
    @forelse ($interactionIds as $id)
        @livewire(\CharlesStOlive\FilamentPrism\Livewire\AiInteractionCard::class, ['interactionId' => $id, 'linksToThread' => $threadId === null], key('fp-interaction-'.$id))
    @empty
        <p class="fp-muted">Aucune demande pour l’instant.</p>
    @endforelse
</div>
