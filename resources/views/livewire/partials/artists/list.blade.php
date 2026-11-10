{{-- Verzeichnis, linke Spalte: Suche, Filter, Werkzeuge, Liste --}}
@php $groups = collect(\App\Livewire\Artists::FILTERS)->groupBy(fn ($f) => $f[0], true); @endphp
<div class="flex w-96 shrink-0 flex-col border-r border-zinc-800" @if (($combiJob['running'] ?? false) || ($aliasJob['running'] ?? false)) wire:poll.4s @endif>
    <div class="space-y-2 border-b border-zinc-800 p-3">
        <div class="flex gap-2">
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="Name oder Schreibweise suchen …" class="input-sm min-w-0 flex-1">
            {{-- Werkzeuge für alle Einträge --}}
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <button x-on:click="open = ! open" class="btn-ghost btn-sm whitespace-nowrap">Werkzeuge ▾</button>
                <div x-show="open" x-cloak x-transition class="absolute right-0 z-30 mt-1 w-80 space-y-0.5 rounded-xl border border-zinc-700 bg-zinc-900 p-1.5 text-sm shadow-2xl">
                    <button wire:click="$set('newOpen', true)" x-on:click="open = false" class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800">+ Neuer Künstler …</button>
                    @if ($combiTotal)
                        <button wire:click="startCombiCheck" x-on:click="open = false" @disabled($combiJob['running'] ?? false) class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800 disabled:opacity-50"
                                title="Bei Deezer, Spotify und MusicBrainz nachsehen, welche Künstler bei euren Liedern eingetragen sind: ein Act oder eine Zusammenarbeit? Schon geprüfte werden übersprungen.">
                            Kombi-Namen prüfen <span class="text-zinc-500">({{ $combiTotal }})</span>
                        </button>
                    @endif
                    @if ($withMbid)
                        <button wire:click="loadAllMusicBrainzAliases" x-on:click="open = false" @disabled($aliasJob['running'] ?? false) class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800 disabled:opacity-50"
                                title="Für Künstler mit MusicBrainz-ID alle dort bekannten Schreibweisen übernehmen (im Hintergrund, ca. 1 pro Sekunde)">
                            Schreibweisen von MusicBrainz holen <span class="text-zinc-500">({{ $withMbid }})</span>
                        </button>
                    @endif
                    @if ($withoutGenre)
                        <button wire:click="fillDefaultGenres" x-on:click="open = false" data-confirm-label="Übernehmen" wire:confirm="Für {{ $withoutGenre }} Künstler ohne Standard-Genre den Vorschlag aus dem Bestand übernehmen? Vorhandene Einträge bleiben unverändert."
                                class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800">
                            Standard-Genre aus Bestand <span class="text-zinc-500">({{ $withoutGenre }} ohne Genre)</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- laufende Hintergrund-Aufgaben --}}
        @if ($combiJob['running'] ?? false)<div class="text-xs text-blue-300">Kombi-Prüfung … {{ $combiJob['done'] ?? 0 }}/{{ $combiJob['total'] ?? 0 }}</div>@endif
        @if ($aliasJob['running'] ?? false)
            <div class="text-xs text-blue-300">Schreibweisen von MusicBrainz … {{ $aliasJob['done'] ?? 0 }}/{{ $aliasJob['total'] ?? 0 }}</div>
        @elseif (isset($aliasJob['finished_at']) && now()->diffInMinutes($aliasJob['finished_at'], true) < 30)
            <div class="text-xs text-zinc-500">Schreibweisen von MusicBrainz: {{ $aliasJob['added'] }} neu{{ ($aliasJob['errors'] ?? 0) ? ', '.$aliasJob['errors'].' Fehler' : '' }}.</div>
        @endif

        {{-- Filter: kombinierbar, mit Anzahl --}}
        <div class="space-y-1.5" x-data="{ more: {{ collect($filters)->intersect(['homonym', 'aliases', 'in_lib', 'inbox_only'])->isNotEmpty() || $genreFilter !== '' ? 'true' : 'false' }} }">
            @foreach ($groups as $group => $items)
                <div class="flex flex-wrap gap-1" @if ($group === 'Sonstiges') x-show="more" x-cloak @endif>
                    @foreach ($items as $key => [, $label])
                        @php $on = in_array($key, $filters, true); $n = $filterCounts[$key] ?? 0; @endphp
                        <button wire:click="toggleFilter('{{ $key }}')" wire:key="fc{{ $key }}" @disabled(! $n && ! $on)
                                class="rounded-full border px-2 py-0.5 text-xs transition disabled:opacity-40 {{ $on ? ($key === 'collab' ? 'border-amber-500/60 bg-amber-500/15 text-amber-100' : 'border-blue-500/60 bg-blue-500/15 text-blue-100') : 'border-zinc-700 text-zinc-400 hover:border-zinc-500 hover:text-zinc-200' }}">
                            {{ $label }} <span class="tabular-nums {{ $on ? 'opacity-80' : 'text-zinc-500' }}">{{ $n }}</span>
                        </button>
                    @endforeach
                </div>
            @endforeach
            <div class="flex flex-wrap items-center gap-2 text-xs" x-show="more" x-cloak>
                <select wire:model.live="genreFilter" class="input-sm w-auto py-0.5 text-xs">
                    <option value="">Alle Genres</option>
                    <option value="-">ohne Genre</option>
                    @foreach ($genreFilterOptions as $g => $n)<option value="{{ $g }}">{{ $g }} ({{ $n }})</option>@endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 text-xs text-zinc-500">
                <button x-on:click="more = ! more" class="hover:text-zinc-200" x-text="more ? '− weniger Filter' : '+ mehr Filter'"></button>
                @if ($filters || $genreFilter !== '' || $q !== '')<button wire:click="clearFilters" class="hover:text-zinc-200">· zurücksetzen</button>@endif
                <select wire:model.live="listSort" class="input-sm ml-auto w-auto py-0.5 text-xs" title="Sortierung">
                    <option value="name">A–Z</option>
                    <option value="tracks">meiste Titel</option>
                    <option value="updated">zuletzt geändert</option>
                </select>
            </div>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-2">
        @if (in_array('splits', $filters, true))
            {{-- gemerkte Trennungen --}}
            <p class="px-2 pb-1 text-xs text-zinc-500">Diese Kombi-Namen werden in die einzelnen Künstler getrennt. ✕ = nicht mehr trennen (geschriebene Tags bleiben).</p>
            @forelse ($splits as $sp)
                <div class="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm hover:bg-zinc-800/60" wire:key="sp{{ $sp->id }}">
                    <span class="min-w-0 flex-1 truncate text-zinc-200" title="{{ $sp->name }} → {{ implode(' · ', $sp->parts) }}">{{ $sp->name }} <span class="text-zinc-500">→ {{ implode(' · ', $sp->parts) }}</span></span>
                    <button wire:click="deleteSplit({{ $sp->id }})" data-confirm-label="Nicht mehr trennen" wire:confirm="„{{ $sp->name }}“ nicht mehr trennen? Bereits geschriebene ARTISTS-Tags bleiben; das Aufräumen meldet den Namen wieder unter „Kombi mit &“." class="text-zinc-500 hover:text-red-400" title="Nicht mehr trennen">✕</button>
                </div>
            @empty
                <p class="p-4 text-sm text-zinc-500">Keine gemerkten Trennungen.</p>
            @endforelse
        @else
            <div class="flex items-center justify-between px-2 pb-1 text-[11px] text-zinc-500"><span>{{ $artistTotal }} von {{ $allTotal }} Einträgen</span></div>
            @forelse ($artists as $a)
                <button wire:click="select({{ $a->id }})" wire:key="ar{{ $a->id }}" @if (($dirty ?? false) && $selected !== $a->id) data-confirm-label="Verwerfen" wire:confirm="Ungespeicherte Änderungen an „{{ $form['name'] ?? '' }}“ verwerfen?" @endif
                        class="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-left text-sm transition {{ $selected === $a->id ? 'bg-blue-600/20 ring-1 ring-blue-500/40' : 'hover:bg-zinc-800' }}">
                    @if ($a->imageUrl())
                        <img src="{{ $a->imageUrl() }}" class="size-8 shrink-0 rounded-full object-cover">
                    @else
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-zinc-800 text-xs text-zinc-400">{{ mb_substr($a->name, 0, 1) }}</span>
                    @endif
                    <span class="min-w-0 flex-1">
                        <span class="block truncate" title="{{ $a->name }}">{{ $a->name }}</span>
                        <span class="block truncate text-[11px] text-zinc-500">{{ $tracksById[$a->id] ?: 'keine' }} Titel{{ ($inboxById[$a->id] ?? 0) ? ' · '.$inboxById[$a->id].' in Inbox' : '' }}{{ $a->default_genre ? ' · '.$a->default_genre : '' }}</span>
                    </span>
                    @if ($a->name_confirmed === 'manual')
                        <span class="shrink-0 text-xs text-emerald-400" title="Name passt – von dir bestätigt">✓</span>
                    @elseif ($a->name_confirmed === 'auto')
                        <span class="shrink-0 text-xs text-sky-400/70" title="Name passt – automatisch: {{ $a->name_confirmed_reason }}">✓</span>
                    @endif
                    @if ($a->name_confirmed !== 'manual' && ($combis[$a->name]['verdict'] ?? null) === 'collab')<span class="shrink-0 text-xs text-amber-300" title="Kombi-Prüfung: {{ $combis[$a->name]['reason'] }}">⚠</span>@endif
                    @if ($a->aliases_count)<span class="shrink-0 text-xs text-zinc-500" title="{{ $a->aliases_count }} Schreibweise(n)">+{{ $a->aliases_count }}</span>@endif
                </button>
            @empty
                <p class="p-4 text-sm text-zinc-500">{{ $allTotal ? 'Keine Einträge für diese Suche bzw. Filter.' : 'Noch leer. Unter „Schnell erfassen“ und „Neue Künstler erfassen“ lassen sich Künstler übernehmen.' }}</p>
            @endforelse
            @if ($artistTotal > $artists->count())
                <div class="flex items-center gap-2 px-2 py-3">
                    <button wire:click="moreArtists" class="btn-ghost btn-sm">Mehr anzeigen</button>
                    <span class="text-xs text-zinc-500">{{ $artists->count() }} von {{ $artistTotal }}</span>
                </div>
            @endif
        @endif
    </div>
</div>
