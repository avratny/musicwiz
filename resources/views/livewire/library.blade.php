@php
    $blackhole = $area === 'blackhole';
    $entry = fn (bool $active) => 'flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm transition '.($active ? 'bg-blue-600/20 text-blue-100 ring-1 ring-blue-500/40' : 'text-zinc-300 hover:bg-zinc-800');
@endphp
{{-- nachfragen nur, solange der Scan dieses Bereichs läuft bzw. etwas in die Inbox unterwegs ist --}}
<div class="flex h-full flex-col overflow-hidden" @if ((($scan['running'] ?? false) && ($scan['location'] ?? null) === $area) || ! empty($busy)) wire:poll.3s @endif>
    <x-page-header :title="$blackhole ? 'Blackhole' : 'Lib'" :subtitle="$artistCount.' Interpreten · '.$total.' Alben'.($blackhole ? ' – hier wird nichts bearbeitet, nur zurück in die Inbox geholt' : '')">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Interpret, Titel oder Album …" class="input w-72">
        <button wire:click="startScan('{{ $area }}')" class="btn-primary" @disabled($scan['running'] ?? false)>
            <svg class="size-4 {{ ($scan['running'] ?? false) && $scan['location'] === $area ? 'animate-spin' : '' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0 0 12.3 2M18.4 9A7 7 0 0 0 6.1 7"/></svg>
            {{ $blackhole ? 'Blackhole' : 'Lib' }} scannen
        </button>
        @if (($scan['running'] ?? false) && ($scan['location'] ?? null) === $area)
            <button wire:click="cancelScan" class="btn-ghost" @disabled(($scan['phase'] ?? '') === 'wird abgebrochen …') title="Bis dahin Gelesenes bleibt – der nächste Scan macht dort weiter">Abbrechen</button>
        @endif
    </x-page-header>

    @include('livewire.partials.scan-notes', ['location' => $area, 'pad' => true])
    @if (session('status'))
        <div class="mx-6 mt-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif

    {{-- Ansicht und Filter --}}
    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-zinc-800 px-6 py-2">
        <div class="flex rounded-lg border border-zinc-700 p-0.5 text-sm">
            @foreach (['albums' => 'Alben', 'table' => 'Tabelle'] as $k => $label)
                <button wire:click="setView('{{ $k }}')" class="rounded-md px-3 py-1 transition {{ $view === $k ? 'bg-zinc-700 text-white' : 'text-zinc-400 hover:text-zinc-200' }}">{{ $label }}</button>
            @endforeach
        </div>
        <input type="search" wire:model.live.debounce.300ms="filter" placeholder="Filtern: Titel, Interpret, Album, Genre, Jahr …" class="input-sm w-72">
        <select wire:model.live="format" class="input-sm w-auto">
            <option value="">Alle Formate</option>
            @foreach ($formats as $f)<option value="{{ $f }}">{{ strtoupper($f) }}</option>@endforeach
            @if ($format !== '' && ! in_array($format, $formats, true))<option value="{{ $format }}">{{ strtoupper($format) }}</option>@endif
        </select>
        <select wire:model.live="genre" class="input-sm w-auto max-w-56">
            <option value="">Alle Genres</option>
            @foreach ($genres as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
            @if ($genre !== '' && ! in_array($genre, $genres, true))<option value="{{ $genre }}">{{ $genre }}</option>@endif
        </select>
        <select wire:model.live="rating" class="input-sm w-auto">
            <option value="">Alle Bewertungen</option>
            @foreach (\App\Livewire\Library::RATING_FILTERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <button wire:click="$toggle('youtube')" class="rounded-lg border px-3 py-1 text-sm transition {{ $youtube ? 'border-red-500/60 bg-red-500/15 text-red-200' : 'border-zinc-700 text-zinc-400 hover:text-zinc-200' }}">Von YouTube</button>
        @if ($filtering)
            <button wire:click="clearFilters" class="btn-ghost btn-sm">Filter zurücksetzen</button>
        @endif
        <span class="ml-auto text-xs text-zinc-500">
            {{ $albums->count().' '.($albums->count() === 1 ? 'Album' : 'Alben').($trackCount !== null ? ' · '.$trackCount.' Titel' : '') }}
        </span>
    </div>

    <div class="flex min-h-0 flex-1 overflow-hidden">
        @if (! $searching)
            {{-- Interpreten: alle auf einmal, Buchstaben springen nur in der Liste --}}
            <div class="flex w-80 shrink-0 border-r border-zinc-800">
                <div class="min-h-0 flex-1 overflow-y-auto p-2" x-ref="list">
                    <button wire:click="showAll" class="{{ $entry($artist === '' && ! $sampler && ! $recent) }}">
                        <span class="flex-1">Alle</span>
                        <span class="text-xs text-zinc-500">{{ $total }}</span>
                    </button>
                    <button wire:click="showRecent" class="{{ $entry($recent) }}">
                        <span class="flex-1">Zuletzt hinzugefügt</span>
                    </button>
                    @if ($samplerCount)
                        <button wire:click="showSampler" class="{{ $entry($sampler) }}">
                            <span class="flex-1 text-violet-300">Sampler</span>
                            <span class="text-xs text-zinc-500">{{ $samplerCount }}</span>
                        </button>
                    @endif
                    @forelse ($artists as $l => $group)
                        <div id="{{ $area }}-letter-{{ md5($l) }}" class="sticky top-0 z-10 mt-2 bg-zinc-950/95 px-3 py-1 text-xs font-semibold text-zinc-500 backdrop-blur">{{ $l }}</div>
                        @foreach ($group as $a)
                            <button wire:click="showArtist(@js($a->folder_artist))" wire:key="ar{{ md5($a->folder_artist) }}" class="{{ $entry($artist === $a->folder_artist) }}"
                                    @if ($artist === $a->folder_artist) x-data x-init="$el.scrollIntoView({ block: 'center' })" @endif>
                                <span class="flex-1 truncate" title="{{ $a->folder_artist }}">{{ $a->folder_artist }}</span>
                                <span class="text-xs text-zinc-500">{{ $a->n }}</span>
                            </button>
                        @endforeach
                    @empty
                        <p class="p-4 text-sm text-zinc-500">Noch keine Interpreten – erst scannen.</p>
                    @endforelse
                </div>
                @if ($artists->count() > 1)
                    <nav class="flex w-7 shrink-0 flex-col items-center justify-center gap-px py-2 text-[11px] font-medium text-zinc-500">
                        @foreach ($artists->keys() as $l)
                            <button type="button" class="w-full rounded hover:bg-zinc-800 hover:text-white" title="{{ $l }}"
                                    x-on:click="document.getElementById('{{ $area }}-letter-{{ md5($l) }}')?.scrollIntoView({ block: 'start' })">{{ mb_strlen($l) > 1 ? '#' : $l }}</button>
                        @endforeach
                    </nav>
                @endif
            </div>
        @endif
            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                @if ($searching)
                    <h2 class="mb-4 text-lg font-semibold">Suche: „{{ trim($q) }}“</h2>
                @elseif ($artist !== '')
                    <div class="mb-6 flex items-center gap-4">
                        @if ($artistModel?->imageUrl())
                            <img src="{{ $artistModel->imageUrl() }}" class="size-20 rounded-full object-cover ring-2 ring-violet-500/50">
                        @else
                            <div class="grid size-20 place-items-center rounded-full bg-gradient-to-br from-blue-600/40 to-violet-600/40 text-2xl font-semibold">{{ mb_substr($artist, 0, 1) }}</div>
                        @endif
                        <div>
                            <h2 class="text-2xl font-semibold">{{ $artist }}</h2>
                            <p class="text-sm text-zinc-400">{{ $albums->count() }} Alben @if ($artistModel?->default_genre) · {{ $artistModel->default_genre }} @endif</p>
                        </div>
                    </div>
                @else
                    <h2 class="mb-4 text-lg font-semibold">{{ $sampler ? 'Sampler' : ($recent ? 'Zuletzt hinzugefügt' : 'Alle') }}</h2>
                @endif
                @if ($view === 'table')
                    @include('livewire.partials.track-table', ['groups' => $groups, 'blackhole' => $blackhole, 'truncated' => $truncated, 'ratings' => $ratings, 'canRate' => $canRate, 'busy' => $busy ?? [], 'busyAlbums' => $busyAlbums ?? []])
                @else
                    @include('livewire.partials.album-grid', ['albums' => $albums, 'blackhole' => $blackhole, 'ratings' => $ratings, 'canRate' => $canRate, 'showArtist' => $artist === '', 'busyAlbums' => $busyAlbums ?? []])
                    @if ($hiddenAlbums)
                        <div class="mt-6 flex items-center gap-3">
                            <button wire:click="loadMore" class="btn-ghost">Mehr laden</button>
                            <span class="text-sm text-zinc-500">noch {{ $hiddenAlbums }} {{ $hiddenAlbums === 1 ? 'Album' : 'Alben' }}</span>
                        </div>
                    @endif
                    @if ($truncated)
                        <p class="mt-3 text-sm text-amber-300">Die Suche liefert nur die ersten {{ \App\Livewire\Library::TABLE_LIMIT }} Titel – es fehlen womöglich Alben. Suchbegriff genauer fassen.</p>
                    @endif
                @endif
            </div>
    </div>

    @if ($blackhole && ($openAlbum ?? null))
        {{-- Blackhole: Titelliste statt Editor --}}
        <div class="fixed inset-0 z-40 grid place-items-center bg-black/60 p-6" x-data="{ downOutside: false }" x-on:keydown.escape.window="$wire.set('open', null)"
             x-on:mousedown.self="downOutside = true" x-on:click.self="if (downOutside) $wire.set('open', null); downOutside = false"
             x-on:mouseup="if ($event.target !== $el) downOutside = false">
            <div class="card flex max-h-full w-full max-w-3xl flex-col overflow-hidden">
                <div class="flex items-center gap-4 border-b border-zinc-800 p-4">
                    <x-cover :url="$openAlbum->coverUrl(120)" class="size-16 shrink-0 rounded-lg" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-lg font-semibold">{{ $openAlbum->title }}</div>
                        <div class="truncate text-sm text-zinc-400">{{ $openAlbum->is_compilation ? $openAlbum->category : $openAlbum->folder_artist }} @if ($openAlbum->year)· {{ $openAlbum->year }}@endif · {{ $openAlbum->track_count }} Tracks</div>
                    </div>
                    @if (isset($busyAlbums[$openAlbum->id]))
                        <span class="text-sm text-amber-300">wird in die Inbox verschoben …</span>
                    @else
                        <button wire:click="albumToInbox({{ $openAlbum->id }})" wire:confirm="Album „{{ $openAlbum->title }}“ in die Inbox verschieben?" class="btn-primary btn-sm">Album → In die Inbox</button>
                    @endif
                    <button wire:click="$set('open', null)" class="btn-ghost btn-sm" title="Schließen">✕</button>
                </div>
                <div class="min-h-0 overflow-y-auto">
                    <table class="w-full text-sm">
                        <tbody>
                        @foreach ($openTracks as $t)
                            <tr wire:key="ot{{ $t->id }}" class="hover:bg-zinc-900">
                                <td class="td w-10"><x-play-button :id="$t->id" :seconds="$t->duration" :title="$t->title" :artist="$t->artist" :album="$t->album" :cover="$openAlbum->coverUrl(120)" /></td>
                                <td class="td w-12 text-zinc-500">{{ $t->track }}</td>
                                <td class="td">{{ $t->title }}<div class="text-xs text-zinc-500">{{ $t->artist }}</div></td>
                                <td class="td w-16 text-zinc-500">{{ $t->durationLabel() }}</td>
                                <td class="td w-36 text-right">
                                    @if (isset($busy[$t->path]))
                                        <span class="text-xs text-amber-300">wird verschoben …</span>
                                    @else
                                        <button wire:click="trackToInbox({{ $t->id }})" wire:confirm="„{{ $t->title ?: $t->filename }}“ in die Inbox verschieben?" class="btn-ghost btn-sm">→ In die Inbox</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
