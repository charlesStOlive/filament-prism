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
                        {{ \Illuminate\Support\Str::headline($entry['field']) }}
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
                </span>
            </label>
        </div>
    @endforeach
</div>
