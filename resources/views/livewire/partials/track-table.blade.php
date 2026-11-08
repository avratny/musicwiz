{{-- Titel als Tabelle mit Trennzeile pro Album; Spalten per Klick sortierbar (Album/Jahr ordnen die Alben) --}}
@php
    $columns = ['nr' => ['Nr', 'w-14'], 'title' => ['Titel', ''], 'artist' => ['Interpret', ''], 'genre' => ['Genre', 'w-40'], 'year' => ['Jahr', 'w-16'], 'format' => ['Format', 'w-24'], 'rating' => ['Bewertung', 'w-28'], 'duration' => ['Länge', 'w-16 text-right']];
    $colspan = count($columns) + 1 + ($blackhole ? 1 : 0);
@endphp
<table class="w-full text-sm">
    <thead class="sticky -top-6 z-10 bg-zinc-950">
        <tr>
            <th class="th w-10"></th>
            @foreach ($columns as $key => [$label, $class])
                <th class="th {{ $class }}">
                    <button wire:click="sortBy('{{ $key }}')" class="inline-flex items-center gap-1 hover:text-zinc-200 {{ $sort === $key ? 'text-zinc-100' : '' }}">
                        {{ $label }}@if ($sort === $key)<span class="text-blue-400">{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif
                    </button>
                </th>
            @endforeach
            @if ($blackhole)<th class="th w-36"></th>@endif
        </tr>
    </thead>
    <tbody>
    @forelse ($groups as ['album' => $album, 'tracks' => $list])
        {{-- Trenner: Album --}}
        <tr wire:key="ag{{ $album->id }}">
            <td colspan="{{ $colspan }}" class="border-t border-zinc-800 pt-4 pb-1.5">
                <div class="flex items-center gap-3">
                    <x-cover :url="$album->coverUrl(120)" class="size-10 shrink-0 rounded" />
                    <div class="min-w-0 flex-1">
                        @if ($blackhole)
                            <button wire:click="$set('open', {{ $album->id }})" class="truncate font-semibold hover:underline">{{ $album->title }}</button>
                        @else
                            <a href="{{ route('library.album', $album) }}" wire:navigate class="truncate font-semibold hover:underline">{{ $album->title }}</a>
                        @endif
                        <div class="truncate text-xs text-zinc-400">
                            {{ $album->is_compilation ? 'Sampler' : $album->folder_artist }}@if ($album->year) · {{ $album->year }}@endif · {{ $album->track_count }} Tracks · {{ $album->durationLabel() }}
                        </div>
                    </div>
                    <x-stars :value="$ratings[$album->id] ?? null" :method="$canRate ? 'rateAlbum' : null" :id="$album->id" />
                    <span class="badge-violet">{{ $album->category }}</span>
                    @if ($blackhole)
                        @if (isset($busyAlbums[$album->id]))
                            <span class="text-xs text-amber-300">wird in die Inbox verschoben …</span>
                        @else
                            <button wire:click="albumToInbox({{ $album->id }})" data-confirm-label="In die Inbox" wire:confirm="Album „{{ $album->title }}“ in die Inbox verschieben?" class="btn-ghost btn-sm">Album → In die Inbox</button>
                        @endif
                    @endif
                </div>
            </td>
        </tr>
        @foreach ($list as $t)
            <tr wire:key="tt{{ $t->id }}" class="hover:bg-zinc-900">
                <td class="td"><x-play-button :id="$t->id" :seconds="$t->duration" :title="$t->title" :artist="$t->artist" :album="$t->album" :cover="$album->coverUrl(120)" /></td>
                <td class="td text-zinc-500">{{ (int) $t->disc > 1 ? $t->disc.'-' : '' }}{{ $t->track }}</td>
                <td class="td">{{ $t->title ?: $t->filename }}</td>
                <td class="td text-zinc-300">{{ $t->artist }}</td>
                <td class="td truncate text-zinc-400" title="{{ implode(', ', $t->genres ?? []) }}">{{ implode(', ', $t->genres ?? []) }}</td>
                <td class="td text-zinc-400">{{ $t->year }}</td>
                <td class="td text-zinc-400">{{ strtoupper($t->ext) }}@if ($t->bitrate && ! in_array(strtolower($t->ext), ['flac', 'wav', 'aiff'], true)) {{ round($t->bitrate / 1000) }}@endif
                    @if ($t->isFromYouTube())<span class="ml-1 rounded bg-red-500/15 px-1 text-[10px] font-semibold text-red-300" title="Von YouTube">YT</span>@endif
                </td>
                <td class="td"><x-stars :value="$t->rating" :method="$canRate ? 'rateTrack' : null" :id="$t->id" /></td>
                <td class="td text-right text-zinc-400">{{ $t->durationLabel() }}</td>
                @if ($blackhole)
                    <td class="td text-right">
                        @if (isset($busy[$t->path]))
                            <span class="text-xs text-amber-300">wird verschoben …</span>
                        @else
                            <button wire:click="trackToInbox({{ $t->id }})" data-confirm-label="In die Inbox" wire:confirm="„{{ $t->title ?: $t->filename }}“ in die Inbox verschieben?" class="btn-ghost btn-sm">→ In die Inbox</button>
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
    @empty
        <tr><td colspan="{{ $colspan }}" class="td py-10 text-center text-zinc-500">Nichts gefunden.</td></tr>
    @endforelse
    </tbody>
</table>
@if ($truncated)
    <p class="mt-3 text-sm text-amber-300">Nur die ersten {{ \App\Livewire\Library::TABLE_LIMIT }} Titel – mit dem Filter eingrenzen.</p>
@endif
