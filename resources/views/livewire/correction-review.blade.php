<div class="fi-fp-correction-review space-y-4">
    {{-- Non échappé à dessein : $rendererView est une View (le diff), pas un texte brut — {{ }}
         afficherait son HTML tel quel au lieu de le rendre (voir TextDiffRenderer/text-diff.blade.php). --}}
    {!! $rendererView !!}

    <div class="flex items-center justify-end gap-x-2 border-t border-gray-200 pt-4 dark:border-white/10">
        <x-filament::button color="gray" wire:click="discard">
            Ignorer
        </x-filament::button>

        <x-filament::button color="gray" outlined wire:click="applySelected">
            Appliquer la sélection
        </x-filament::button>

        <x-filament::button wire:click="applyAll">
            Tout appliquer
        </x-filament::button>
    </div>
</div>
