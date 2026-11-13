@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/[\s\-&,.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
    $hue = fn (string $name) => crc32($name) % 360;
@endphp
<div class="flex min-h-full" @keydown.escape.window="$wire.selected && $wire.close()">
    {{-- zweite Leiste: Werkzeuge --}}
    <aside class="sticky top-0 h-[calc(100vh-4rem)] w-52 shrink-0 border-r border-zinc-800 bg-zinc-950 px-3 py-4">
        <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Interpreten</div>
        @foreach (\App\Support\ArtistTools::TOOLS as $key => $label)
            <button wire:click="$set('tool', '{{ $key }}')"
                    @class(['flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-sm',
                            'bg-accent/10 text-accent font-medium' => $tool === $key,
                            'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => $tool !== $key])>
                @if ($key === 'aehnlich')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><path d="M5 7h9M5 12h14M5 17h9"/><path d="m16 5 3 2-3 2M16 15l3 2-3 2"/></svg>
                @elseif ($key === 'eindeutig')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z"/><path d="m9 12 2 2 4-4"/></svg>
                @elseif ($key === 'wahrscheinlich')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6z"/><path d="M10 9.5a2 2 0 1 1 2.8 1.8c-.5.3-.8.7-.8 1.2v.5M12 16h.01"/></svg>
                @elseif ($key === 'schreibweise')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></svg>
                @elseif ($key === 'kombis')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><circle cx="16" cy="16" r="3"/><path d="M10.5 10.5l3 3M16 5v6M19 8h-6"/></svg>
                @elseif ($key === 'sortiernamen')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 6h9M4 12h6M4 18h3M17 4v16m0 0-3-3m3 3 3-3"/></svg>
                @elseif ($key === 'verschieden')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><path d="M5 9h14M5 15h14M15 5 9 19"/></svg>
                @else
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg>
                @endif
                <span class="flex-1 leading-tight">{{ $label }}</span>
                @if ($key === '' && $unconfirmed)
                    <span class="rounded-full bg-amber-500/15 px-1.5 text-xs tabular-nums text-amber-300 hover:bg-amber-500/25" wire:click.stop="showOpen"
                          title="{{ $fmt($unconfirmed) }} unbestätigt – anzeigen">{{ $fmt($unconfirmed) }}</span>
                @endif
                <span class="rounded-full px-1.5 text-xs tabular-nums text-zinc-500">{{ $fmt($counts[$key]) }}</span>
            </button>
        @endforeach
    </aside>

    <div class="min-w-0 flex-1">
    @if (in_array($tool, \App\Support\ClearArtists::LEVELS, true))
        <livewire:artist-clear :level="$tool" wire:key="tool-{{ $tool }}" />
    @elseif ($tool === 'aehnlich')
        <livewire:artist-similar wire:key="tool-aehnlich" />
    @elseif ($tool === 'kombis')
        <livewire:artist-combos wire:key="tool-kombis" />
    @elseif ($tool === 'verschieden')
        <livewire:artist-distinct wire:key="tool-verschieden" />
    @elseif ($tool === 'sortiernamen')
        <livewire:artist-sort-names wire:key="tool-sortiernamen" />
    @else
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-3">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Interpreten</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Interpret' : 'Interpreten' }}</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="q" placeholder="Interpret oder Schreibweise suchen …" class="field pl-8">
            </div>
            <button class="btn" @click="Livewire.dispatch('open-artist-create', { context: 'artist-list' })" title="Einen Interpreten von Hand anlegen">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                Interpret anlegen
            </button>
            <button class="btn" wire:click="startLookup" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('lookup')"
                    title="Interpreten aus Lib und Inbox mit MusicBrainz, Spotify, Discogs, TheAudioDB und Fanart.tv abgleichen">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0 0 12.8 1.5M18.4 9A7 7 0 0 0 5.6 7.5"/></svg>
                Abgleichen @if ($pendingLookup)<span class="text-xs text-zinc-500">({{ $fmt($pendingLookup) }} offen)</span>@endif
            </button>
            <div class="flex rounded-md border border-zinc-700 p-0.5 text-sm">
                @foreach (['all' => 'Alle', 'confirmed' => 'Bestätigt', 'open' => 'Offen'] as $key => $label)
                    <button wire:click="$set('status', '{{ $key }}')"
                            @class(['rounded px-3 py-1 font-medium', 'bg-zinc-700 text-zinc-100' => $status === $key, 'text-zinc-400 hover:text-zinc-200' => $status !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </header>

    @php
        $th = function (string $key, string $align = 'left') use ($sort, $dir) {
            $arrow = $sort === $key ? '<span class="text-accent">'.($dir === 'asc' ? '↑' : '↓').'</span>' : '';
            return '<button class="flex w-full items-center gap-1 uppercase hover:text-zinc-100 '.($align === 'right' ? 'justify-end' : '').'" wire:click="sortBy(\''.$key.'\')">'
                .e(\App\Livewire\ArtistList::SORTS[$key]).' '.$arrow.'</button>';
        };
    @endphp
    <table class="w-full table-fixed border-separate border-spacing-0 text-sm">
        <colgroup><col class="w-20"><col><col class="w-32"><col class="w-[28%]"><col class="w-24"><col class="w-24"><col class="w-32"></colgroup>
        <thead class="sticky top-[61px] z-10 bg-zinc-950 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
            <tr>
                <th class="border-b border-zinc-800 px-6 py-2"></th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('name') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('confirmed') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('aliases') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('tracks', 'right') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('albums', 'right') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2 pr-6">{!! $th('created_at', 'right') !!}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($artists as $a)
                <tr wire:key="a-{{ $a->id }}" wire:click="open({{ $a->id }})"
                    @class(['cursor-pointer', 'bg-accent/15' => $selected === $a->id, 'hover:bg-zinc-900' => $selected !== $a->id])>
                    <td class="border-b border-zinc-800/70 py-2 pl-6 pr-2">
                        @if ($a->image)
                            <img src="{{ route('artist.image', [$a->id, 64]) }}?v={{ $a->updated_at?->timestamp }}" loading="lazy" alt="" class="size-10 rounded-full bg-zinc-800 object-cover"
                                 onerror="this.style.visibility='hidden'">
                        @else
                            <div class="flex size-10 items-center justify-center rounded-full text-xs font-semibold text-white/90"
                                 style="background: hsl({{ $hue($a->name) }} 35% 30%)">{{ $initials($a->name) }}</div>
                        @endif
                    </td>
                    <td class="truncate border-b border-zinc-800/70 px-2 py-2 font-medium" title="{{ $a->name }}">{{ $a->name }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2">
                        @if ($a->confirmed)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-400">
                                <svg class="size-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>bestätigt
                            </span>
                        @else
                            <span class="text-xs text-zinc-600">offen</span>
                        @endif
                    </td>
                    <td class="truncate border-b border-zinc-800/70 px-2 py-2 text-xs text-zinc-400" title="{{ $a->aliases->pluck('alias')->implode(' · ') }}">
                        {{ $a->aliases->pluck('alias')->implode(' · ') }}
                    </td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-right tabular-nums text-zinc-300">{{ $fmt($a->track_count) }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-right tabular-nums text-zinc-400">{{ $fmt($a->album_count) }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 pr-6 text-right tabular-nums text-xs text-zinc-500">{{ $a->created_at?->format('d.m.Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-16 text-center text-zinc-500">{{ $q ? 'Nichts gefunden.' : 'Noch keine Interpreten – sie werden beim Einlesen aus den Tags erfasst.' }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($more)
        <div class="py-6 text-center" x-intersect.margin.600px="$wire.loadMore()">
            <button class="btn" wire:click="loadMore">Mehr laden</button>
        </div>
    @endif

    @endif
    </div>

    {{-- Flyout: Einzelheiten, Pflege, Lieder --}}
    @if ($artist)
        @php
            $queue = $detail['songs']->map(fn ($t) => ['id' => $t->id, 'title' => $t->title ?: $t->filename, 'artist' => $t->artist, 'cover' => $t->id, 'duration' => $t->duration])->values()->all();
        @endphp
        <aside class="fixed right-0 top-0 z-40 flex flex-col border-l border-zinc-800 bg-zinc-900 shadow-2xl" wire:key="artist-flyout-{{ $artist->id }}" x-data="flyoutPanel" :style="style">
            <div class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize transition-colors hover:bg-accent/40" :class="resizing && 'bg-accent/60'"
                 @mousedown.prevent="startResize($event)" @dblclick="resetWidth()" title="Breite ziehen (Doppelklick: Standard)"></div>
            <div class="relative border-b border-zinc-800 p-5">
                <button class="absolute right-3 top-3 rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" wire:click="close" title="Schließen (Esc)">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
                <div class="flex items-center gap-4">
                    <div class="group relative size-28 shrink-0 overflow-hidden rounded-full bg-zinc-800">
                        @if ($artist->imagePath())
                            <img src="{{ route('artist.image', [$artist->id, 300]) }}?v={{ $artist->updated_at?->timestamp }}" alt="" class="size-full object-cover">
                        @else
                            <div class="flex size-full items-center justify-center text-3xl font-semibold text-white/90" style="background: hsl({{ $hue($artist->name) }} 35% 30%)">{{ $initials($artist->name) }}</div>
                        @endif
                        <label class="absolute inset-0 flex cursor-pointer items-center justify-center bg-black/55 text-xs font-medium text-white opacity-0 transition group-hover:opacity-100" title="Bild hochladen">
                            <input type="file" accept="image/*" wire:model="photo" class="hidden">
                            <span wire:loading.remove wire:target="photo">Bild ändern</span>
                            <span wire:loading wire:target="photo">lädt …</span>
                        </label>
                    </div>
                    <div class="min-w-0">
                        <div class="text-xl font-semibold leading-tight">{{ $artist->name }}</div>
                        <div class="mt-1 text-sm text-zinc-400">{{ $fmt($detail['tracks']) }} {{ $detail['tracks'] === 1 ? 'Lied' : 'Lieder' }} · {{ $fmt($detail['albums']) }} {{ $detail['albums'] === 1 ? 'Album' : 'Alben' }}@if ($detail['guest']) · davon {{ $fmt($detail['guest']) }} als Gast @endif</div>
                        <button wire:click="toggleConfirmed"
                                @class(['mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium', 'bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25' => $artist->confirmed, 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700' => ! $artist->confirmed])>
                            <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>
                            {{ $artist->confirmed ? 'Schreibweise bestätigt' : 'Schreibweise bestätigen' }}
                        </button>
                        @if ($artist->image)
                            <button class="ml-2 text-xs text-zinc-500 hover:text-zinc-300" wire:click="removeImage">Bild entfernen</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex-1 space-y-6 overflow-y-auto p-5">
                @if ($error)
                    <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
                @elseif ($message)
                    <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
                @endif
                @error('photo')<div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $message }}</div>@enderror

                {{-- Name --}}
                <form wire:submit="rename" class="space-y-1">
                    <span class="block text-xs font-medium text-zinc-500">Name (richtige Schreibweise)</span>
                    <div class="flex gap-2">
                        <input type="text" wire:model="name" class="field">
                        <button type="submit" class="btn shrink-0">Umbenennen</button>
                    </div>
                </form>

                {{-- Schreibweisen --}}
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Alternative Schreibweisen</div>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse ($artist->aliases as $alias)
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-800 py-1 pl-3 pr-1 text-sm" wire:key="alias-{{ $alias->id }}">
                                {{ $alias->alias }}
                                <button class="rounded-full p-0.5 text-zinc-500 hover:bg-zinc-700 hover:text-zinc-200" wire:click="removeAlias(@js($alias->alias))" title="Schreibweise entfernen">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </span>
                        @empty
                            <span class="text-sm text-zinc-500">Noch keine.</span>
                        @endforelse
                    </div>
                    <form wire:submit="addAlias" class="mt-2 flex gap-2">
                        <input type="text" wire:model="newAlias" placeholder="Schreibweise hinzufügen, z. B. „Abba“" class="field">
                        <button type="submit" class="btn shrink-0">Hinzufügen</button>
                    </form>
                </div>

                {{-- Zusammenführen --}}
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Ist derselbe wie …</div>
                    <input type="search" wire:model.live.debounce.250ms="mergeQuery" placeholder="anderen Interpreten suchen" class="field">
                    @if ($candidates->isNotEmpty())
                        <ul class="mt-2 divide-y divide-zinc-800 rounded-md border border-zinc-800">
                            @foreach ($candidates as $c)
                                <li class="flex items-center gap-2 px-3 py-1.5 text-sm" wire:key="cand-{{ $c->id }}">
                                    <span class="flex-1 truncate">{{ $c->name }}</span>
                                    <button class="text-xs font-medium text-accent hover:underline" wire:click="merge({{ $c->id }})"
                                            data-confirm-title="Als Schreibweise übernehmen" data-confirm-ok="Übernehmen" data-confirm="„{{ $c->name }}“ wird zur Schreibweise von „{{ $artist->name }}“.{{ \App\Support\ArtistRegistry::conflict($artist, $c) ? ' Achtung: Die beiden haben verschiedene MusicBrainz-Einträge – vermutlich sind es zwei verschiedene. Behalten wird der Eintrag von „'.$artist->name.'“.' : '' }} Fortfahren?">hierher übernehmen</button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($mergeQuery)) >= 2)
                        <p class="mt-2 text-sm text-zinc-500">Kein anderer Interpret gefunden.</p>
                    @endif
                </div>

                {{-- Mitglieder bzw. Gruppen laut MusicBrainz (lokale Daten) --}}
                @if ($band)
                    @foreach (['members' => 'Mitglieder', 'memberOf' => 'Mitglied von'] as $key => $label)
                        @if ($band[$key])
                            <div>
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }} <span class="font-normal normal-case text-zinc-600">laut MusicBrainz</span></div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($band[$key] as $m)
                                        @if ($m['id'])
                                            <button wire:click="open({{ $m['id'] }})" @class(['rounded-full bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700 hover:text-accent', 'opacity-60' => $m['ended']])
                                                    title="{{ $m['ended'] ? 'ehemals' : '' }}">{{ $m['name'] }}</button>
                                        @else
                                            <span @class(['rounded-full border border-zinc-800 px-3 py-1 text-sm text-zinc-500', 'opacity-60' => $m['ended']])
                                                  title="nicht in deiner Sammlung{{ $m['ended'] ? ' · ehemals' : '' }}">{{ $m['name'] }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                @endif

                {{-- als verschieden markiert („Interpreten › Ähnlich“) --}}
                @if ($distinctFrom->isNotEmpty())
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Nicht derselbe wie</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($distinctFrom as $d)
                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-800 py-1 pl-3 pr-1 text-sm" wire:key="distinct-{{ $d->id }}">
                                    <button class="hover:text-accent" wire:click="open({{ $d->id }})">{{ $d->name }}</button>
                                    <button class="rounded-full p-0.5 text-zinc-500 hover:bg-zinc-700 hover:text-zinc-200" wire:click="undistinct({{ $artist->id }}, {{ $d->id }})"
                                            title="Aufheben – darf wieder als ähnlich vorgeschlagen werden">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                    </button>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Genres (jede Ebene; in die Tags kommt am Ende nur das Hauptgenre) --}}
                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Genres</div>
                    @forelse ($genres as $root => $list)
                        <div class="mb-2" wire:key="ag-{{ md5($root) }}">
                            <div class="text-sm font-semibold text-zinc-200">{{ $root }}</div>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach ($list as $genreId => $g)
                                    @if ($g['level'] > 1 || count($list) === 1)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-800 py-0.5 pl-2.5 pr-1 text-xs text-zinc-300" title="{{ $g['path'] }}">
                                            {{ $g['level'] > 1 ? $g['name'] : $root }}
                                            <span class="text-[10px] text-zinc-500">{{ collect($g['sources'])->keys()->map(fn ($s) => \App\Support\ArtistGenres::SOURCES[$s] ?? $s)->implode(', ') }}</span>
                                            @if (isset($g['sources']['manual']))
                                                <button class="rounded-full p-0.5 text-zinc-500 hover:bg-zinc-700 hover:text-zinc-200" wire:click="removeGenre({{ $genreId }})" title="Manuelle Zuordnung entfernen">
                                                    <svg class="size-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                                </button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">Noch keine – aus Tags und Abgleich oder manuell.</p>
                    @endforelse
                    <div class="relative mt-2">
                        <input type="search" wire:model.live.debounce.200ms="genreQuery" placeholder="Genre hinzufügen …" class="field">
                        @if ($genreResults->isNotEmpty())
                            <div class="absolute inset-x-0 z-10 mt-1 rounded-md border border-zinc-700 bg-zinc-900 p-1 shadow-xl">
                                @foreach ($genreResults as $r)
                                    <button class="block w-full truncate rounded px-2 py-1 text-left text-sm text-zinc-300 hover:bg-zinc-800" wire:click="addGenre({{ $r['id'] }})">{{ $r['path'] }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Abgleich mit den Diensten --}}
                @php
                    $serviceLabels = \App\Models\ArtistSource::SERVICES;
                    $stateLabels = ['ok' => 'gefunden', 'nicht_gefunden' => 'nicht gefunden', 'fehler' => 'Fehler', 'gesperrt' => 'gesperrt – wird nachgeholt', 'uebersprungen' => 'übersprungen'];
                    $matchLabels = ['exakt' => 'Name exakt', 'gross_klein' => 'Groß/klein anders', 'zeichen' => 'gleich bis auf Zeichen', 'schreibweise' => 'bekannte Schreibweise', 'aehnlich' => 'ähnlich', 'verknuepft' => 'über MusicBrainz verknüpft'];
                @endphp
                <div @if ($awaiting) wire:poll.3s @endif>
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Abgleich</span>
                        <button class="text-xs font-medium text-accent hover:underline disabled:opacity-50" wire:click="relookup" @disabled($awaiting)>
                            {{ $awaiting ? 'läuft …' : ($artist->looked_up_at ? 'Neu abgleichen' : 'Jetzt abgleichen') }}</button>
                    </div>
                    @if ($lookupStatus->isNotEmpty())
                        <div class="mb-3 flex flex-wrap gap-1.5 text-[11px]">
                            @foreach ($serviceLabels as $service => $label)
                                @php $st = $lookupStatus[$service] ?? null; @endphp
                                @if ($st)
                                    <span title="{{ $st->message }} · {{ \Illuminate\Support\Carbon::parse($st->fetched_at)->format('d.m.Y H:i') }}"
                                          @class(['rounded px-1.5 py-0.5', 'bg-emerald-500/15 text-emerald-300' => $st->status === 'ok', 'bg-zinc-800 text-zinc-400' => in_array($st->status, ['nicht_gefunden', 'uebersprungen'], true), 'bg-red-500/15 text-red-300' => $st->status === 'fehler', 'bg-amber-500/15 text-amber-300' => $st->status === 'gesperrt'])>
                                        {{ $label }}: {{ $stateLabels[$st->status] ?? $st->status }}</span>
                                @endif
                            @endforeach
                        </div>
                    @elseif (! $artist->looked_up_at)
                        <p class="text-sm text-zinc-500">Noch nicht abgeglichen.</p>
                    @endif

                    @foreach ($lookupGroups as $g)
                        <div wire:key="lg-{{ md5($g['key']) }}" @class(['mb-3 rounded-lg border p-3', 'border-emerald-500/50 bg-emerald-500/5' => $g['chosen'], 'border-zinc-800' => ! $g['chosen']])>
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($g['services'] as $service)
                                            <span class="rounded bg-zinc-800 px-1.5 py-0.5 text-[11px] font-medium text-zinc-300">{{ $serviceLabels[$service] }}</span>
                                        @endforeach
                                    </div>
                                    <div class="mt-1.5 text-xs text-zinc-500">
                                        {{ collect([$matchLabels[$g['match']] ?? null, $g['type'], $g['country'], $g['begin'] ? 'seit '.substr($g['begin'], 0, 4) : null, $g['disambiguation'],
                                            ...collect($g['releases'])->map(fn ($n, $service) => number_format($n, 0, ',', '.').' Veröffentl. ('.$service.')')->values(),
                                            $g['listeners'] ? number_format($g['listeners'], 0, ',', '.').' Hörer (Last.fm)' : null])->filter()->implode(' · ') }}
                                    </div>
                                </div>
                                @if ($g['chosen'])
                                    <span class="shrink-0 text-xs font-medium text-emerald-400">✓ gewählt</span>
                                @else
                                    <button class="btn shrink-0 !py-1 text-xs" wire:click="chooseGroup(@js($g['key']))">Diesen nehmen</button>
                                @endif
                            </div>

                            {{-- Schreibweisen der Dienste – die häufigste zuerst --}}
                            <div class="mt-2 space-y-1">
                                @foreach ($g['spellings'] as $spelling => $services)
                                    <div class="flex items-center gap-2 text-sm">
                                        <span @class(['truncate', 'font-semibold' => $loop->first])>{{ $spelling }}</span>
                                        <span class="truncate text-[11px] text-zinc-500">{{ implode(', ', $services) }}</span>
                                        @if ($spelling !== $artist->name && \App\Support\ArtistMatcher::plain($spelling) === \App\Support\ArtistMatcher::plain($artist->name))
                                            <span class="ml-auto shrink-0 text-[11px] text-zinc-500" title="sieht gleich aus, nutzt aber andere Zeichen (z. B. typografischer Bindestrich oder Apostroph)">= Name, nur andere Zeichen</span>
                                        @elseif ($spelling !== $artist->name)
                                            <button class="ml-auto shrink-0 text-[11px] text-accent hover:underline" wire:click="adoptName(@js($spelling))"
                                                    data-confirm-title="Als Namen übernehmen" data-confirm-ok="Übernehmen" data-confirm="„{{ $spelling }}“ wird der Name – „{{ $artist->name }}“ bleibt als Schreibweise, Lieder werden angepasst. Fortfahren?">als Namen übernehmen</button>
                                        @else
                                            <span class="ml-auto shrink-0 text-[11px] text-emerald-400">= Name</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if ($g['genres'])
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($g['genres'] as $genre)<span class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-400">{{ $genre }}</span>@endforeach
                                </div>
                            @endif
                            @if ($g['text'])
                                <p class="mt-2 line-clamp-3 text-xs text-zinc-400" title="{{ $g['text'] }}">{{ $g['text'] }}</p>
                            @endif

                            @if ($g['images'])
                                <div class="mt-3 grid grid-cols-5 gap-1.5">
                                    {{-- Vorschau, die noch fehlt, lädt die Adresse sofort nach; nicht erreichbare Bilder verschwinden --}}
                                    @foreach ($g['images'] as $img)
                                        <button class="group relative aspect-square overflow-hidden rounded bg-zinc-800" wire:click="useImage({{ $img['id'] }})"
                                                title="{{ $img['service'] }} · {{ ['portrait' => 'Porträt', 'background' => 'Hintergrund', 'logo' => 'Logo'][$img['kind']] ?? $img['kind'] }} – als Bild übernehmen">
                                            <img src="{{ route('artist.preview', $img['id']) }}?v={{ $img['version'] }}" loading="lazy" alt="" class="size-full object-cover" onerror="this.closest('button').remove()">
                                            <span class="absolute inset-x-0 bottom-0 bg-black/60 px-1 py-0.5 text-[9px] text-white/80">{{ $img['service'] }}</span>
                                            <span class="absolute inset-0 hidden items-center justify-center bg-black/50 text-[10px] font-medium text-white group-hover:flex">übernehmen</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            @if ($g['aliases'])
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs text-zinc-500">Schreibweisen laut Diensten ({{ count($g['aliases']) }})</summary>
                                    <div class="mt-1.5 flex flex-wrap gap-1">
                                        @foreach ($g['aliases'] as $alias => $services)
                                            <button class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300 hover:bg-zinc-700" wire:click="adoptAlias(@js($alias))"
                                                    title="{{ implode(', ', $services) }} – als Schreibweise übernehmen">{{ $alias }} +</button>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                    @if ($artist->mbid || $artist->discogs_id || $artist->spotify_id)
                        <div class="text-[11px] text-zinc-500">
                            @if ($artist->mbid)<a class="hover:text-accent" target="_blank" rel="noopener" href="https://musicbrainz.org/artist/{{ $artist->mbid }}">MusicBrainz ↗</a>@endif
                            @if ($artist->discogs_id) · <a class="hover:text-accent" target="_blank" rel="noopener" href="https://www.discogs.com/artist/{{ $artist->discogs_id }}">Discogs ↗</a>@endif
                            @if ($artist->spotify_id) · <a class="hover:text-accent" target="_blank" rel="noopener" href="https://open.spotify.com/artist/{{ $artist->spotify_id }}">Spotify ↗</a>@endif
                        </div>
                    @endif
                </div>

                {{-- Lieder --}}
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Lieder</span>
                        @if ($queue)
                            <button class="text-xs font-medium text-accent hover:underline" @click="$store.player.play(@js($queue), 0)">▶ Alle abspielen</button>
                        @endif
                    </div>
                    <ul class="space-y-0.5">
                        @forelse ($detail['songs'] as $i => $t)
                            <li class="group flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 hover:bg-zinc-800" wire:key="song-{{ $t->id }}"
                                @click="$store.player.play(@js($queue), {{ $i }})" :class="$store.player.isCurrent({{ $t->id }}) && 'text-accent'">
                                @if (in_array($t->location, \App\Support\SplitAlbums::LOCATIONS, true))
                                    <img src="{{ \App\Support\Covers::url($t, 64) }}" loading="lazy" alt="" class="cover-link size-9 shrink-0 rounded bg-zinc-800 object-cover" onerror="noCover(this)"
                                         @click.stop="$dispatch('open-cover-picker', { track: {{ $t->id }} })" title="Cover ändern">
                                @else
                                    <img src="{{ \App\Support\Covers::url($t, 64) }}" loading="lazy" alt="" class="size-9 shrink-0 rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'">
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm">{{ $t->title ?: $t->filename }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $t->album }}@if ($t->location !== 'library') · {{ \App\Support\Locations::LABELS[$t->location] }}@endif</div>
                                </div>
                                <span class="text-xs tabular-nums text-zinc-500">{{ $t->durationLabel() }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-zinc-500">Keine Lieder unter diesem Namen.</li>
                        @endforelse
                    </ul>
                    @if ($detail['tracks'] > $detail['songs']->count())
                        <a href="{{ route('location', ['slug' => 'lib', 'q' => $artist->name]) }}" wire:navigate class="mt-2 inline-block text-xs text-accent hover:underline">
                            Alle {{ $fmt($detail['tracks']) }} Lieder in der Lib suchen →</a>
                    @endif
                </div>
            </div>
        </aside>
    @endif
</div>
