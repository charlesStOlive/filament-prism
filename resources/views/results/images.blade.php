{{-- Voir ImageResultRenderer. Classes fp-* : voir resources/css/filament-prism.css. --}}
<div class="fp-image-result">
    @if ($sources !== [])
        <div class="fp-image-result-group">
            <span class="fp-image-result-title">{{ count($sources) > 1 ? 'Photos de départ' : 'Photo de départ' }}</span>
            <div class="fp-image-result-grid fp-image-result-grid-sources">
                @foreach ($sources as $source)
                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="fp-image-result-item">
                        <img src="{{ $source['url'] }}" alt="{{ $source['label'] ?? '' }}" loading="lazy">
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="fp-image-result-group">
        <span class="fp-image-result-title">Résultat</span>
        <div class="fp-image-result-grid">
            @forelse ($images as $image)
                <a href="{{ $image['url'] }}" target="_blank" rel="noopener" class="fp-image-result-item fp-image-result-item-output">
                    <img src="{{ $image['url'] }}" alt="Image produite par l’IA" loading="lazy">
                </a>
            @empty
                <span class="fp-muted">Aucune image.</span>
            @endforelse
        </div>
    </div>
</div>
