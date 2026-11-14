@php
    $all = \App\Livewire\TrackTable::COLUMNS;
    $editable = \App\Models\Track::EDITABLE;
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $hms = function (int $s) { return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60); };
    $queueItem = fn ($t, $cover = null) => ['id' => $t->id, 'title' => $t->title ?: $t->filename, 'artist' => $t->artist, 'cover' => $cover ?? $t->id, 'duration' => $t->duration];
    $colCount = count($columns) + 1;
    // Cover ändern (Klick aufs Cover) geht in Inbox und Lib
    $canCover = in_array($location, \App\Support\SplitAlbums::LOCATIONS, true);
@endphp
<div x-data="trackTable" @keydown.escape.window="sel.length && clear()" class="flex min-h-full">
    <div class="min-w-0 flex-1" x-data="{ head: 61 }" :style="`--head: ${head}px`">
    {{-- Kopf (Höhe gemessen: die Spaltenköpfe kleben direkt darunter, auch wenn der Kopf umbricht) --}}
    <header x-init="new ResizeObserver(() => head = $el.offsetHeight).observe($el)" class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-3">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">{{ $title }}</h1>
                <p class="text-xs text-zinc-500">
                    {{ $fmt($totalTracks) }} {{ $totalTracks === 1 ? 'Lied' : 'Lieder' }}@if ($grouped) · {{ $fmt($totalGroups) }} {{ $totalGroups === 1 ? 'Album' : 'Alben' }}@endif
                </p>
            </div>
            <div class="relative min-w-48 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="q" placeholder="Suchen: Titel, Interpret, Album, Datei …" class="field pl-8">
            </div>
            <button class="btn" wire:click="$toggle('showFilters')" @class(['!border-accent !text-accent' => $showFilters || array_filter($filters)])>
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 5h16l-6 8v5l-4 2v-7z"/></svg>
                Filter
            </button>
            <label class="flex cursor-pointer select-none items-center gap-2 text-sm text-zinc-300">
                <input type="checkbox" wire:model.live="grouped" class="size-4 rounded border-zinc-700 accent-[var(--color-accent)]">
                Nach Album gruppiert
            </label>
            <select class="field w-auto" wire:change="sortBy($event.target.value)" title="Sortierung">
                @foreach (array_merge(\App\Livewire\TrackTable::SORTS, array_map(fn ($c) => $c['label'], $all)) as $key => $label)
                    <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn" wire:click="sortBy('{{ $sort }}')" title="Richtung umkehren">{{ $dir === 'asc' ? '↑' : '↓' }}</button>
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                <button class="btn" @click="open = !open" title="Spalten ein- und ausblenden">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 5h16v14H4zM10 5v14M16 5v14"/></svg>
                    Spalten
                </button>
                <div x-cloak x-show="open" x-transition.opacity.duration.100ms
                     class="absolute right-0 z-30 mt-1 w-56 rounded-lg border border-zinc-800 bg-zinc-900 p-2 shadow-lg">
                    @foreach ($all as $key => $c)
                        <label @class(['flex items-center gap-2 rounded px-2 py-1.5 text-sm', 'cursor-pointer hover:bg-zinc-800' => ! in_array($key, \App\Livewire\TrackTable::REQUIRED, true), 'text-zinc-500' => in_array($key, \App\Livewire\TrackTable::REQUIRED, true)])>
                            <input type="checkbox" value="{{ $key }}" wire:model.live="visible" class="size-4 rounded border-zinc-700 accent-[var(--color-accent)]"
                                   @if (in_array($key, \App\Livewire\TrackTable::REQUIRED, true)) checked disabled @endif>
                            {{ $c['label'] }}
                        </label>
                    @endforeach
                    <button class="mt-1 w-full rounded px-2 py-1.5 text-left text-xs text-accent hover:bg-zinc-800" wire:click="resetColumns">Standard wiederherstellen</button>
                </div>
            </div>
            <button class="btn-primary" wire:click="scan" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('scan')">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0 0 12.8 1.5M18.4 9A7 7 0 0 0 5.6 7.5"/></svg>
                Einlesen
            </button>
        </div>
    </header>

    {{-- Tabelle --}}
    <table class="w-full table-fixed border-separate border-spacing-0 text-sm" @click="rowClick($event)" @dblclick="edit($event)"
           @mousedown="$event.shiftKey && $event.preventDefault()">
        <colgroup>
            <col class="w-14">
            @foreach ($columns as $c)<col @class([$c['width']])>@endforeach
        </colgroup>
        <thead class="sticky top-[var(--head)] z-10 bg-zinc-950 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
            <tr>
                <th class="border-b border-zinc-800 px-3 py-2"></th>
                @foreach ($columns as $key => $c)
                    <th class="border-b border-zinc-800 px-2 py-2">
                        <button class="flex items-center gap-1 truncate uppercase hover:text-zinc-100" wire:click="sortBy('{{ $key }}')">
                            {{ $c['label'] }}
                            @if ($sort === $key)<span class="text-accent">{{ $dir === 'asc' ? '↑' : '↓' }}</span>@endif
                        </button>
                    </th>
                @endforeach
            </tr>
            @if ($showFilters)
                <tr class="normal-case">
                    <th class="border-b border-zinc-800 px-3 py-1.5"></th>
                    @foreach ($columns as $key => $c)
                        <th class="border-b border-zinc-800 px-1 py-1.5 font-normal">
                            @if ($c['filter'])
                                <input type="text" wire:model.live.debounce.300ms="filters.{{ $key }}" placeholder="filtern" class="field px-1.5 py-1 text-xs">
                            @endif
                        </th>
                    @endforeach
                </tr>
            @endif
        </thead>

        @if ($grouped)
            @forelse ($groups as $g)
                @php $queue = $g['tracks']->values()->map(fn ($t) => $queueItem($t, $g['loose'] ? null : $g['cover']))->all(); @endphp
                <tbody wire:key="g-{{ $g['hash'] }}" data-queue="{{ json_encode($queue) }}">
                    <tr data-noflyout>
                        <td colspan="{{ $colCount }}" class="cursor-pointer border-b border-zinc-800 bg-zinc-900 px-3 pb-2 pt-5 hover:bg-zinc-800/70"
                            @click="!$event.target.closest('button, input') && selectGroup($el)" title="Album auswählen">
                            <div class="flex items-center gap-4">
                                <input type="checkbox" class="size-3.5 shrink-0 rounded border-zinc-600 accent-[var(--color-accent)]" title="Ganzes Album auswählen"
                                       :checked="groupState($el) === 'all'" :indeterminate="groupState($el) === 'some'" @click.stop="toggleGroup($el)">
                                {{-- Einzeltitel: kein gemeinsames Cover – jedes Lied hat sein eigenes (in der Zeile) --}}
                                <div @class(['group relative size-16 shrink-0 overflow-hidden rounded-md bg-zinc-800 shadow-sm', 'cover-link' => $canCover && ! $g['loose']])
                                     @if ($canCover && ! $g['loose']) @click.stop="$dispatch('open-cover-picker', { album: @js($g['hash']) })" title="Cover ändern" @endif>
                                    @if ($g['loose'])
                                        <svg class="size-full p-4 text-zinc-600" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                                    @else
                                        <img src="{{ \App\Support\Covers::url($g['cover'], 300) }}" loading="lazy" class="size-full object-cover" onerror="noCover(this)" alt="">
                                    @endif
                                    <button class="absolute bottom-1 right-1 flex size-7 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition hover:bg-accent hover:text-zinc-950 group-hover:opacity-100"
                                            @click.stop="playFrom($el, 0)" title="Album abspielen">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                    </button>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate text-base font-semibold">{{ $g['loose'] ? basename($g['dir']) : ($g['album'] ?: basename($g['dir'])) }}</span>
                                        @if ($g['loose'])<span class="shrink-0 rounded bg-zinc-800 px-1.5 py-0.5 text-[11px] font-medium text-zinc-400" title="Kein gemeinsames Album: Cover, Kategorie und Bewertung je Lied">Einzeltitel</span>@endif
                                    </div>
                                    <div class="truncate text-sm text-zinc-400">{{ $g['albumartist'] ?: 'Unbekannter Interpret' }}</div>
                                    <div class="mt-0.5 truncate text-xs text-zinc-500">
                                        {{ collect([$g['year'], $g['genre'], $g['discs'] > 1 ? $g['discs'].' CDs' : null, $g['tracks']->count().' '.($g['tracks']->count() === 1 ? 'Lied' : 'Lieder'), $hms($g['duration'])])->filter()->implode(' · ') }}
                                        <span class="ml-2" title="{{ $g['dir'] }}">{{ \Illuminate\Support\Str::after($g['dir'], \App\Support\Locations::root($location).'/') }}</span>
                                    </div>
                                    @unless ($g['loose'])
                                        <div class="mt-1">
                                            @include('livewire.partials.stars', ['value' => $g['rating'], 'method' => $this->canEdit() ? 'rateAlbum' : null, 'arg' => $g['hash'], 'model' => null, 'large' => false])
                                        </div>
                                    @endunless
                                </div>
                                @if ($this->hasCategories())
                                    {{-- Kategorie fürs ganze Album: wohin es beim Schreiben einsortiert wird --}}
                                    @php
                                        $target = count($g['categories']) === 1 ? \App\Support\Categories::LOCATIONS[$g['categories'][0]] : null;
                                    @endphp
                                    <div class="ml-auto shrink-0 text-right">
                                        @include('livewire.partials.category-picker', ['current' => $g['categories'], 'method' => 'setAlbumCategory', 'arg' => $g['hash'], 'small' => false, 'model' => null])
                                        @if ($g['loose'])
                                            <div class="mt-1 text-[11px] text-zinc-500">für alle Lieder – auch je Lied wählbar</div>
                                        @elseif (count($g['categories']) > 1)
                                            <div class="mt-1 text-[11px] text-amber-300">Verschiedene Kategorien im Album</div>
                                        @elseif ($location !== 'inbox' && $target && $target !== $location)
                                            <div class="mt-1 text-[11px] text-amber-300">wird beim Schreiben nach {{ \App\Support\Locations::LABELS[$target] }} verschoben</div>
                                        @endif
                                    </div>
                                @elseif (! $this->canEdit())
                                    {{-- Blackhole: nur zurück in die Inbox (verschoben wird beim Schreiben) --}}
                                    <div class="ml-auto shrink-0 text-right">
                                        @if ($g['toInbox'] === $g['tracks']->count())
                                            <span class="text-xs text-amber-300">wird beim Schreiben in die Inbox verschoben</span>
                                            <button type="button" class="btn ml-2 !py-1 text-xs" wire:click="moveAlbumToInbox(@js($g['hash']), false)">Aufheben</button>
                                        @else
                                            @if ($g['toInbox'])<span class="text-xs text-amber-300">{{ $g['toInbox'] }} von {{ $g['tracks']->count() }} für die Inbox vorgemerkt</span>@endif
                                            <button type="button" class="btn ml-2 !py-1 text-xs" wire:click="moveAlbumToInbox(@js($g['hash']))">→ In die Inbox</button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @foreach ($g['tracks']->values() as $i => $t)
                        {{-- mehrere CDs: ein Album, nur eine dezente Trennzeile je CD --}}
                        @if ($g['discs'] > 1 && ($i === 0 || $g['tracks']->values()[$i - 1]->disc !== $t->disc))
                            <tr data-noflyout><td colspan="{{ $colCount }}" class="border-b border-zinc-800/70 px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">CD {{ $t->disc ?: '?' }}</td></tr>
                        @endif
                        @include('livewire.partials.track-row', ['t' => $t, 'index' => $i, 'showCover' => false, 'pickCategory' => $g['loose'], 'rowCover' => $g['loose']])
                    @endforeach
                    @foreach ($plans[$g['hash']] ?? [] as $p)
                        <tr data-noflyout wire:key="plan-{{ $p->id }}">
                            <td colspan="{{ $colCount }}" class="border-b border-zinc-800/70 px-3 py-1.5 text-sm italic text-zinc-500">
                                <span class="mr-2 rounded bg-sky-500/15 px-1.5 py-0.5 text-[11px] font-medium not-italic text-sky-300">vorgemerkt</span>
                                Nr. {{ $p->values['track'] }} · {{ $p->track?->title }} – {{ $p->track?->artist }}
                                <span class="text-xs">({{ $p->action === 'move' ? 'wird verschoben' : 'wird kopiert' }} aus {{ \App\Support\Locations::LABELS[$p->track?->location] ?? '?' }}{{ $p->replaces_track_id ? ' und ersetzt die schlechtere Fassung' : '' }} – beim Schreiben)</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="{{ $colCount }}" class="px-6 py-16 text-center text-zinc-500">
                    {{ $q || array_filter($filters) ? 'Nichts gefunden.' : 'Noch nichts eingelesen – oben rechts „Einlesen“.' }}
                </td></tr></tbody>
            @endforelse
        @else
            @php $queue = $list->values()->map(fn ($t) => $queueItem($t))->all(); @endphp
            <tbody data-queue="{{ json_encode($queue) }}">
                @forelse ($list->values() as $i => $t)
                    @include('livewire.partials.track-row', ['t' => $t, 'index' => $i, 'showCover' => true, 'pickCategory' => ! $t->album])
                @empty
                    <tr><td colspan="{{ $colCount }}" class="px-6 py-16 text-center text-zinc-500">
                        {{ $q || array_filter($filters) ? 'Nichts gefunden.' : 'Noch nichts eingelesen – oben rechts „Einlesen“.' }}
                    </td></tr>
                @endforelse
            </tbody>
        @endif
    </table>

    @if ($more)
        <div class="py-6 text-center" x-intersect.margin.600px="$wire.loadMore()">
            <button class="btn" wire:click="loadMore">Mehr laden</button>
        </div>
    @endif
    </div>

    @if (! $this->canEdit() && $selection)
        {{-- Blackhole: nichts bearbeiten, nur zurück in die Inbox --}}
        @php
            $items = $track ? collect([$track]) : $picked;
            $marked = $items->where('move_to', 'inbox')->count();
        @endphp
        <aside class="fixed right-0 top-0 z-40 flex flex-col border-l border-zinc-800 bg-zinc-900 shadow-2xl" wire:key="flyout-blackhole" x-data="flyoutPanel" :style="style">
            <div class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize transition-colors hover:bg-accent/40" :class="resizing && 'bg-accent/60'"
                 @mousedown.prevent="startResize($event)" @dblclick="resetWidth()" title="Breite ziehen (Doppelklick: Standard)"></div>
            <div class="flex items-start gap-3 border-b border-zinc-800 p-5">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold">{{ $track ? ($track->title ?: $track->filename) : $items->count().' Lieder ausgewählt' }}</div>
                    <div class="mt-1 line-clamp-3 text-xs text-zinc-500">{{ $track ? $track->artist : $items->take(8)->map(fn ($p) => $p->title ?: $p->filename)->implode(' · ').($items->count() > 8 ? ' …' : '') }}</div>
                </div>
                <button class="rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" @click="clear()" title="Schließen (Esc)">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="flex-1 space-y-4 overflow-y-auto p-5 text-sm">
                <p class="rounded-md bg-zinc-800/60 px-3 py-2 text-xs text-zinc-400">Blackhole wird ignoriert: keine Bearbeitung, kein Abgleich der Interpreten. Von hier geht es nur zurück in die Inbox – verschoben wird beim Schreiben.</p>
                @if ($marked)
                    <p class="text-amber-300">{{ $marked === $items->count() ? 'Für die Inbox vorgemerkt.' : $marked.' von '.$items->count().' für die Inbox vorgemerkt.' }}</p>
                @endif
                <div class="flex gap-2">
                    @if ($marked < $items->count())
                        <button class="btn-primary" wire:click="moveToInbox(@js($selection))">→ In die Inbox</button>
                    @endif
                    @if ($marked)
                        <button class="btn" wire:click="moveToInbox(@js($selection), false)">Vormerkung aufheben</button>
                    @endif
                </div>
                @if ($track)
                    <div class="break-all border-t border-zinc-800/70 pt-4 text-xs text-zinc-500"><span class="font-medium text-zinc-300">Datei:</span> {{ $track->path }}</div>
                @endif
            </div>
        </aside>
    {{-- Flyout rechts: alle Grunddaten bearbeiten --}}
    @elseif ($track)
        <aside class="fixed right-0 top-0 z-40 flex flex-col border-l border-zinc-800 bg-zinc-900 shadow-2xl"
               wire:key="flyout-{{ $track->id }}" x-data="flyoutPanel" :style="style">
            <div class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize transition-colors hover:bg-accent/40" :class="resizing && 'bg-accent/60'"
                 @mousedown.prevent="startResize($event)" @dblclick="resetWidth()" title="Breite ziehen (Doppelklick: Standard)"></div>
            <div class="flex items-start gap-4 border-b border-zinc-800 p-5">
                <img src="{{ \App\Support\Covers::url($track, 300) }}" @class(['size-24 shrink-0 rounded-md bg-zinc-800 object-cover shadow-sm', 'cover-link' => $canCover]) alt=""
                     @if ($canCover) onerror="noCover(this)" @click="$dispatch('open-cover-picker', { track: {{ $track->id }} })" title="Cover ändern" @else onerror="noCover(this)" @endif>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold">{{ $track->title ?: $track->filename }}</div>
                    <div class="truncate text-sm text-zinc-400">{{ $track->artist }}</div>
                    <div class="mt-1 text-xs text-zinc-500">{{ $track->qualityLabel() }} · {{ $track->durationLabel() }} · {{ number_format($track->size / 1048576, 1, ',', '.') }} MB</div>
                    <button class="mt-2 text-xs font-medium text-accent hover:underline"
                            @click="$store.player.play([@js($queueItem($track))], 0)">▶ Abspielen</button>
                </div>
                <button class="rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" @click="clear()" title="Schließen (Esc)">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <form wire:submit="saveFlyout" class="flex-1 space-y-3 overflow-y-auto p-5">
                @foreach (['title', 'artist', 'artists', 'album', 'albumartist', 'albumartists'] as $f)
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">{{ $editable[$f] }}</span>
                        <input type="text" wire:model="form.{{ $f }}" class="field" @if (in_array($f, ['artists', 'albumartists'], true)) placeholder="nur bei mehreren, mit „;“ getrennt" @endif>
                    </label>
                @endforeach
                <div class="grid grid-cols-4 gap-3">
                    @foreach (['track' => 'Nr.', 'track_total' => 'von', 'disc' => 'CD', 'disc_total' => 'von'] as $f => $label)
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-zinc-500">{{ $label }}</span>
                            <input type="text" inputmode="numeric" wire:model="form.{{ $f }}" class="field">
                        </label>
                    @endforeach
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Jahr</span>
                        <input type="text" wire:model="form.year" class="field">
                    </label>
                    <label class="col-span-2 block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Genre</span>
                        <input type="text" wire:model="form.genre" class="field">
                    </label>
                </div>
                <div>
                    <span class="mb-1 block text-xs font-medium text-zinc-500">Bewertung</span>
                    @include('livewire.partials.stars', ['value' => null, 'method' => null, 'model' => 'form.rating', 'large' => true])
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-zinc-500">Kommentar</span>
                    <textarea wire:model="form.comment" rows="2" class="field"></textarea>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="form.compilation" class="size-4 rounded border-zinc-700 accent-[var(--color-accent)]">
                    Sampler (Compilation)
                </label>
                @if ($this->hasCategories())
                    <div>
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Kategorie</span>
                        @include('livewire.partials.category-picker', ['current' => [], 'model' => 'form.category', 'small' => false])
                    </div>
                @endif
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="btn-primary">Speichern</button>
                    @if ($saved)<span class="text-xs text-zinc-500">{{ $saved }}</span>@endif
                </div>

                <div class="space-y-1 border-t border-zinc-800/70 pt-4 text-xs text-zinc-500">
                    <div class="break-all"><span class="font-medium text-zinc-300">Datei:</span> {{ $track->path }}</div>
                    <div>{{ $track->format }} · {{ $track->bitrate ? round($track->bitrate / 1000).' kbit/s' : '–' }} · {{ $track->sample_rate ? number_format($track->sample_rate / 1000, 1, ',', '').' kHz' : '–' }}{{ $track->bits ? ' · '.$track->bits.' Bit' : '' }} · {{ $track->channels }} Kanäle</div>
                    <div>Eingelesen {{ $track->scanned_at?->format('d.m.Y H:i') }}</div>
                    @if ($track->error)<div class="text-red-400">Fehler: {{ $track->error }}</div>@endif
                </div>

                @if ($track->changes->isNotEmpty())
                    <div class="border-t border-zinc-800/70 pt-4">
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Änderungen (noch nicht in der Datei)</div>
                        <ul class="space-y-1.5 text-xs">
                            @foreach ($track->changes as $c)
                                <li class="flex gap-2">
                                    <span @class(['shrink-0 rounded px-1.5 py-0.5 font-medium', 'bg-blue-500/15 text-blue-300' => $c->source === 'manual', 'bg-amber-500/15 text-amber-300' => $c->source !== 'manual'])>{{ $c->source === 'manual' ? 'manuell' : 'System' }}</span>
                                    <span class="min-w-0"><span class="font-medium">{{ $editable[$c->field] ?? $c->field }}:</span>
                                        <span class="text-zinc-500 line-through">{{ $c->old_value ?? '∅' }}</span> → {{ $c->new_value ?? '∅' }}
                                        <span class="text-zinc-500">· {{ $c->created_at?->format('d.m. H:i') }}</span>
                                        @if ($c->reason)<span class="block text-zinc-500">{{ $c->reason }}</span>@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <details class="border-t border-zinc-800/70 pt-4">
                    <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-zinc-500">Alle Tags der Datei ({{ count($track->tags ?? []) }})</summary>
                    <table class="mt-2 w-full text-xs">
                        @foreach ($track->tags ?? [] as $key => $values)
                            <tr class="align-top">
                                <td class="w-2/5 break-all py-0.5 pr-2 font-mono text-zinc-500">{{ $key }}</td>
                                <td class="break-all py-0.5">{{ implode(' · ', array_map(fn ($v) => \Illuminate\Support\Str::limit((string) $v, 300), (array) $values)) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </details>
            </form>
        </aside>
    @elseif ($picked->count() > 1)
        {{-- mehrere Lieder: gemeinsame Felder, übernommen wird nur, was geändert wird --}}
        @php
            $mixedHint = fn ($f) => isset($mixed[$f]) ? '(verschiedene Werte)' : '';
        @endphp
        <aside class="fixed right-0 top-0 z-40 flex flex-col border-l border-zinc-800 bg-zinc-900 shadow-2xl" wire:key="flyout-multi" x-data="flyoutPanel" :style="style">
            <div class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize transition-colors hover:bg-accent/40" :class="resizing && 'bg-accent/60'"
                 @mousedown.prevent="startResize($event)" @dblclick="resetWidth()" title="Breite ziehen (Doppelklick: Standard)"></div>
            <div class="flex items-start gap-4 border-b border-zinc-800 p-5">
                <div class="relative size-24 shrink-0">
                    @foreach ($picked->take(3)->values() as $i => $p)
                        <img src="{{ \App\Support\Covers::url($p, 300) }}" alt=""
                             @class(['absolute size-20 rounded-md bg-zinc-800 object-cover shadow-lg ring-1 ring-zinc-900', 'cover-link' => $canCover]) style="left: {{ $i * 8 }}px; top: {{ $i * 8 }}px; z-index: {{ 3 - $i }}"
                             @if ($canCover) onerror="noCover(this)" @click="$dispatch('open-cover-picker', { track: {{ $p->id }} })" title="Cover ändern" @else onerror="noCover(this)" @endif>
                    @endforeach
                </div>
                <div class="min-w-0 flex-1">
                    <div class="font-semibold">{{ $picked->count() }} Lieder ausgewählt</div>
                    <div class="mt-1 line-clamp-3 text-xs text-zinc-500">{{ $picked->take(8)->map(fn ($p) => $p->title ?: $p->filename)->implode(' · ') }}{{ $picked->count() > 8 ? ' …' : '' }}</div>
                    <button class="mt-2 text-xs font-medium text-accent hover:underline" @click="clear()">Auswahl aufheben</button>
                </div>
                <button class="rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" @click="clear()" title="Schließen (Esc)">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <form wire:submit="saveFlyout" class="flex-1 space-y-3 overflow-y-auto p-5">
                <p class="rounded-md bg-zinc-800/60 px-3 py-2 text-xs text-zinc-400">Gilt für alle ausgewählten Lieder – übernommen wird nur, was du änderst. Titel und Nummer bleiben je Lied.</p>
                @foreach (['artist', 'album', 'albumartist'] as $f)
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">{{ $editable[$f] }}</span>
                        <input type="text" wire:model="form.{{ $f }}" placeholder="{{ $mixedHint($f) }}" class="field placeholder:italic">
                    </label>
                @endforeach
                <div class="grid grid-cols-3 gap-3">
                    @foreach (['track_total' => 'Titel gesamt', 'disc' => 'CD', 'disc_total' => 'CDs gesamt'] as $f => $label)
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-zinc-500">{{ $label }}</span>
                            <input type="text" inputmode="numeric" wire:model="form.{{ $f }}" placeholder="{{ isset($mixed[$f]) ? 'versch.' : '' }}" class="field placeholder:italic">
                        </label>
                    @endforeach
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Jahr</span>
                        <input type="text" wire:model="form.year" placeholder="{{ isset($mixed['year']) ? 'versch.' : '' }}" class="field placeholder:italic">
                    </label>
                    <label class="col-span-2 block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Genre</span>
                        <input type="text" wire:model="form.genre" placeholder="{{ $mixedHint('genre') }}" class="field placeholder:italic">
                    </label>
                </div>
                <div>
                    <span class="mb-1 block text-xs font-medium text-zinc-500">Bewertung @if (isset($mixed['rating']))<span class="italic">(verschiedene Werte – unverändert, solange nichts gewählt)</span>@endif</span>
                    @include('livewire.partials.stars', ['value' => null, 'method' => null, 'model' => 'form.rating', 'large' => true])
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-zinc-500">Kommentar</span>
                    <textarea wire:model="form.comment" rows="2" placeholder="{{ $mixedHint('comment') }}" class="field placeholder:italic"></textarea>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-zinc-500">Sampler (Compilation)</span>
                    <select wire:model="form.compilation" class="field">
                        @if (isset($mixed['compilation']))<option value="">(verschiedene Werte – unverändert)</option>@endif
                        <option value="1">Ja</option>
                        <option value="0">Nein</option>
                    </select>
                </label>
                @if ($this->hasCategories())
                    <div>
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Kategorie @if (isset($mixed['category']))<span class="italic">(verschiedene Werte – unverändert, solange nichts gewählt)</span>@endif</span>
                        @include('livewire.partials.category-picker', ['current' => [], 'model' => 'form.category', 'small' => false])
                    </div>
                @endif
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="btn-primary">Für {{ $picked->count() }} Lieder speichern</button>
                    @if ($saved)<span class="text-xs text-zinc-500">{{ $saved }}</span>@endif
                </div>
            </form>
        </aside>
    @endif
</div>
