<div class="fi-fp-correction-review space-y-4">
    {{ $rendererView }}

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
