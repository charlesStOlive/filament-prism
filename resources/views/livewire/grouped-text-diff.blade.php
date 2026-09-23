<div class="space-y-6">
    @forelse ($items as $item)
        <div class="rounded-lg border border-gray-200 dark:border-white/10">
            <div class="flex items-center gap-x-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <input
                    type="checkbox"
                    wire:model="applyItems.{{ $item['key'] }}"
                    class="rounded border-gray-300 text-primary-600 dark:border-white/20"
                />
                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['key'] }}</span>
            </div>

            <div class="space-y-3 p-3">
                @foreach ($item['fields'] as $entry)
                    <div>
                        <span class="block text-sm font-medium text-gray-950 dark:text-white">
                            {{ $entry['label'] }}
                        </span>

                        @if ($entry['unchanged'])
                            <span class="block text-sm text-gray-500 dark:text-gray-400">Déjà correct, rien à changer.</span>
                        @else
                            <span class="block text-sm leading-relaxed">
                                @foreach ($entry['segments'] as $segment)
                                    @if ($segment['type'] === 'same')
                                        <span>{{ $segment['text'] }}</span>
                                    @elseif ($segment['type'] === 'removed')
                                        <span class="text-danger-600 line-through dark:text-danger-400">{{ $segment['text'] }}</span>
                                    @else
                                        <span class="text-success-600 underline dark:text-success-400">{{ $segment['text'] }}</span>
                                    @endif
                                    {{ ' ' }}
                                @endforeach
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">L’IA n’a renvoyé aucune correction exploitable.</p>
    @endforelse
</div>
