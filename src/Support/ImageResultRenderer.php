<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use Illuminate\Contracts\View\View;

/**
 * Le résultat d'une ressource d'image : les images de départ
 * (`AiResource::sourcePreviews()`), puis celles produites — chacune s'ouvre
 * en grand dans un nouvel onglet.
 */
class ImageResultRenderer implements AiResultRenderer
{
    public function render(AiInteraction $interaction): View
    {
        $tasks = app(AiTaskRegistry::class);

        return view('filament-prism::results.images', [
            'sources' => $tasks->has($interaction->task) ? $tasks->resource($interaction->task)->sourcePreviews($interaction) : [],
            'images' => $interaction->images(),
        ]);
    }
}
