<div class="fi-fp-group-correction-review space-y-4">
    @if ($interaction->isDraft())
        @include('filament-prism::livewire.partials.correction-draft', ['subjects' => $draftSubjects])
    @elseif ($interaction->isStatus(\CharlesStOlive\FilamentPrism\Models\AiInteraction::STATUS_FAILED))
        <p class="fp-interaction-error">{{ $interaction->error ?? 'L’appel à l’IA a échoué.' }}</p>
    @else
        @if ($remark = $interaction->feedback())
            <p class="fp-interaction-feedback"><span><strong>Ce qui n’allait pas :</strong> {{ $remark }}</span></p>
        @endif

        {{-- Non échappé à dessein : $rendererView est une View (le diff), pas un texte brut — {{ }}
             afficherait son HTML tel quel au lieu de le rendre (voir TextDiffRenderer/text-diff.blade.php). --}}
        {!! $rendererView !!}
    @endif

    @include('filament-prism::livewire.partials.correction-footer', ['interaction' => $interaction])
</div>
