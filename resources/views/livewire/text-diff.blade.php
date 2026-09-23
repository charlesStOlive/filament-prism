<div class="space-y-4">
    @foreach ($fields as $entry)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <label class="flex items-start gap-x-2">
                <input
                    type="checkbox"
                    wire:model="applyFields.{{ $entry['field'] }}"
                    @disabled($entry['unchanged'])
                    class="mt-1 rounded border-gray-300 text-primary-600 dark:border-white/20"
                />

                <span class="flex-1">
                    <span class="block text-sm font-medium text-gray-950 dark:text-white">
                        {{ $entry['label'] }}
                    </span>

                    @if ($entry['unchanged'])
                        <span class="block text-sm text-gray-500 dark:text-gray-400">Déjà correct, rien à changer.</span>
                    @else
                        @include('filament-prism::livewire.partials.diff-columns', ['segments' => $entry['segments']])
                    @endif
                </span>
            </label>
        </div>
    @endforeach
</div>
