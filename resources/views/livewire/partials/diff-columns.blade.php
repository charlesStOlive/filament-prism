{{--
    Les deux blocs d'une correction, à partir des segments de WordDiff::compare() : « Avant » montre le texte d'origine
    (mots retirés surlignés en rouge), « Après » le texte corrigé (mots ajoutés surlignés en vert). Un saut de paragraphe
    est un segment à part (WordDiff::LINE_BREAK), rendu en retour à la ligne.
--}}
@php($lineBreak = \CharlesStOlive\FilamentPrism\Support\WordDiff::LINE_BREAK)

<div class="fp-diff-columns">
    <div class="fp-diff-block fp-diff-before">
        <span class="fp-diff-block-title">Avant</span>
        @foreach ($segments as $segment)
            @continue($segment['type'] === 'added')
            @if ($segment['text'] === $lineBreak)
                <br>
            @elseif ($segment['type'] === 'removed')
                <span class="fp-diff-removed">{{ $segment['text'] }}</span>
            @else
                {{ $segment['text'] }}
            @endif
        @endforeach
    </div>

    <div class="fp-diff-block fp-diff-after">
        <span class="fp-diff-block-title">Après</span>
        @foreach ($segments as $segment)
            @continue($segment['type'] === 'removed')
            @if ($segment['text'] === $lineBreak)
                <br>
            @elseif ($segment['type'] === 'added')
                <span class="fp-diff-added">{{ $segment['text'] }}</span>
            @else
                {{ $segment['text'] }}
            @endif
        @endforeach
    </div>
</div>
