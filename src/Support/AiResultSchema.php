<?php

namespace CharlesStOlive\FilamentPrism\Support;

use CharlesStOlive\FilamentPrism\Models\AiInteraction;
use CharlesStOlive\FilamentPrism\Registry\AiTaskRegistry;
use CharlesStOlive\FilamentPrism\Resources\AiResource;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * L'affichage d'une demande IA, en composants Filament (des entrées
 * d'infolist), là où elle se montre — modale, slide-over, volet latéral, page
 * « Demandes IA » : ce que la ressource déclare (`AiResource::resultSchema()`,
 * `AiResource::inputDisplaySchema()`), sinon un affichage par défaut.
 *
 * Par défaut, le résultat est une grille Filament (`Grid`) : une `TextEntry`
 * par valeur de la réponse (`output`), libellée par `meta.labels` quand il y
 * en a ; les images produites (`output.images`) et celles de départ
 * (`AiResource::sourcePreviews()`) en `ImageEntry`, chacune s'ouvrant en grand.
 * Les réglages, par défaut, sont ceux de `AiResource::describeInput()`.
 *
 * Le schéma porte la demande comme `record` : une ressource peut écrire
 * `TextEntry::make('output.summary')`, `TextEntry::make('input.language')`.
 */
final class AiResultSchema
{
    /** @return array<int, Component> */
    public static function result(AiInteraction $interaction): array
    {
        return self::resource($interaction)?->resultSchema($interaction) ?? self::defaultResult($interaction);
    }

    /** @return array<int, Component> */
    public static function input(AiInteraction $interaction): array
    {
        $resource = self::resource($interaction);

        return $resource?->inputDisplaySchema($interaction) ?? self::defaultInput($interaction, $resource);
    }

    /** @return array<int, Component> */
    public static function defaultResult(AiInteraction $interaction): array
    {
        $resource = self::resource($interaction);
        $sources = $resource?->sourcePreviews($interaction) ?? [];
        $images = $interaction->images();
        $labels = (array) ($interaction->meta['labels'] ?? []);

        $components = [];

        if ($sources !== []) {
            $components[] = self::images('sources', count($sources) > 1 ? 'Images de départ' : 'Image de départ', array_column($sources, 'url'), '6rem');
        }

        if ($images !== []) {
            $components[] = self::images('result_images', 'Résultat', array_column($images, 'url'), '16rem');
        }

        $entries = collect(Arr::except((array) ($interaction->output ?? []), ['images']))
            ->map(fn (mixed $value, string|int $key): TextEntry => self::entry((string) $key, $value, $labels[$key] ?? Str::headline((string) $key)))
            ->values()
            ->all();

        if ($entries !== []) {
            $components[] = Grid::make(['default' => 1, 'md' => 2])->schema($entries);
        }

        return $components;
    }

    /** @return array<int, Component> */
    public static function defaultInput(AiInteraction $interaction, ?AiResource $resource): array
    {
        $settings = $resource?->describeInput($interaction->input ?? []) ?? [];
        // Un brouillon n'a pas encore de résultat, où se montrent d'habitude les images de départ : elles sont ici.
        $sources = $interaction->isDraft() ? ($resource?->sourcePreviews($interaction) ?? []) : [];
        $components = $sources === [] ? [] : [self::images('sources', count($sources) > 1 ? 'Images de départ' : 'Image de départ', array_column($sources, 'url'), '6rem')];

        if ($settings === []) {
            return $components;
        }

        return [
            ...$components,
            Grid::make(['default' => 2, 'md' => 4])->schema(collect($settings)
                ->map(fn (string $value, string $label): TextEntry => TextEntry::make('setting_'.Str::slug($label, '_'))
                    ->label($label)
                    ->state($value)
                    ->size(TextSize::Small))
                ->values()
                ->all()),
        ];
    }

    /**
     * Une valeur de la réponse, lisible : oui/non, une liste, du HTML (nettoyé par Filament), un
     * texte long sur toute la largeur, et, faute de mieux, le JSON.
     */
    public static function entry(string $key, mixed $value, string $label): TextEntry
    {
        $entry = TextEntry::make('output_'.Str::slug($key, '_'))->label($label)->placeholder('—');

        return match (true) {
            is_bool($value) => $entry->state($value ? 'Oui' : 'Non'),
            is_array($value) && array_is_list($value) && collect($value)->every(fn (mixed $item): bool => is_scalar($item)) => $entry->state($value)->listWithLineBreaks()->bulleted(),
            is_array($value) || is_object($value) => $entry
                ->state(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->fontFamily(FontFamily::Mono)
                ->extraAttributes(['style' => 'white-space: pre-wrap'])
                ->columnSpanFull(),
            is_string($value) && $value !== strip_tags($value) => $entry->state($value)->html()->columnSpanFull(),
            is_string($value) && mb_strlen($value) > 120 => $entry->state($value)->columnSpanFull(),
            default => $entry->state($value),
        };
    }

    /** @param  array<int, string>  $urls */
    public static function images(string $name, string $label, array $urls, string $height): ImageEntry
    {
        return ImageEntry::make($name)
            ->label($label)
            ->state($urls)
            ->imageHeight($height)
            ->url(fn (string $state): string => $state)
            ->openUrlInNewTab()
            ->columnSpanFull();
    }

    private static function resource(AiInteraction $interaction): ?AiResource
    {
        $tasks = app(AiTaskRegistry::class);
        $task = $tasks->has($interaction->task) ? $tasks->get($interaction->task) : null;

        return $task instanceof AiResource ? $task : null;
    }
}
