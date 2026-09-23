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
                            <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div class="rounded-md border border-danger-200 bg-danger-50 p-2 text-sm leading-relaxed dark:border-danger-400/30 dark:bg-danger-400/10">
                                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-danger-700 dark:text-danger-400">Avant</span>
                                    @foreach ($entry['segments'] as $segment)
                                        @continue($segment['type'] === 'added')
                                        <span @class(['rounded bg-danger-200 px-0.5 dark:bg-danger-400/30' => $segment['type'] === 'removed'])>{{ $segment['text'] }}</span>
                                        {{ ' ' }}
                                    @endforeach
                                </div>

                                <div class="rounded-md border border-success-200 bg-success-50 p-2 text-sm leading-relaxed dark:border-success-400/30 dark:bg-success-400/10">
                                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-success-700 dark:text-success-400">Après</span>
                                    @foreach ($entry['segments'] as $segment)
                                        @continue($segment['type'] === 'removed')
                                        <span @class(['rounded bg-success-200 px-0.5 dark:bg-success-400/30' => $segment['type'] === 'added'])>{{ $segment['text'] }}</span>
                                        {{ ' ' }}
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">L’IA n’a renvoyé aucune correction exploitable.</p>
    @endforelse
</div>
