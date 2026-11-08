{{-- Genre-Vorschläge aus Bestand, MusicBrainz und Discogs; Klick übernimmt ins Feld $model --}}
@if ($proposals)
    <div class="flex flex-wrap gap-2 text-xs">
        @foreach ($proposals as $p)
            @if ($p['genre'])
                <button wire:click="$set('{{ $model }}', @js($p['genre']))" title="{{ $p['detail'] }}"
                        class="rounded-lg border px-2.5 py-1 text-left {{ $current === $p['genre'] ? 'border-blue-500 bg-blue-500/10 text-blue-100' : 'border-zinc-700 text-zinc-300 hover:border-zinc-500' }}">
                    <span class="text-zinc-500">{{ $p['source'] }}:</span> <span class="font-medium">{{ $p['genre'] }}</span>
                    <span class="block max-w-72 truncate text-zinc-500">{{ $p['detail'] }}</span>
                </button>
            @elseif (! empty($p['tied']))
                <span class="rounded-lg border border-zinc-800 px-2.5 py-1 text-zinc-400" title="{{ $p['detail'] }}">
                    {{ $p['source'] }} (gleichauf):
                    @foreach ($p['tied'] as $g)
                        <button wire:click="$set('{{ $model }}', @js($g))" class="ml-1 rounded px-1.5 {{ $current === $g ? 'bg-blue-500/20 text-blue-100' : 'text-zinc-200 hover:bg-zinc-800' }}">{{ $g }}</button>
                    @endforeach
                    <span class="block max-w-72 truncate text-zinc-500">{{ $p['detail'] }}</span>
                </span>
            @else
                <span class="rounded-lg border border-zinc-800 px-2.5 py-1 text-zinc-500" title="{{ $p['detail'] }}">
                    {{ $p['source'] }}: <span class="block max-w-72 truncate">{{ $p['detail'] }}</span>
                </span>
            @endif
        @endforeach
    </div>
@endif
