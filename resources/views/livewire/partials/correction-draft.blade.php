{{-- Voir OrthographyTask::inputDisplaySchema() : les textes d'un brouillon de correction, tels qu'ils partiront. --}}
<div class="fp-correction-draft">
    @forelse ($subjects as $subject)
        <section class="fp-correction-draft-subject">
            @if ($subject['key'] !== null)
                <h4 class="fp-correction-draft-key">{{ $subject['key'] }}</h4>
            @endif

            @foreach ($subject['fields'] as $field)
                <div class="fp-correction-draft-field">
                    <span class="fp-correction-draft-label">{{ $field['label'] }}</span>
                    <p class="fp-correction-draft-text">{{ $field['text'] }}</p>
                </div>
            @endforeach
        </section>
    @empty
        <p class="fp-muted">Aucun texte à corriger.</p>
    @endforelse
</div>
