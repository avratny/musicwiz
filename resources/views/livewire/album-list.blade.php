{{-- Alben: „Alle“ (Liste) bzw. „Auseinandergerissen“ (uneinheitliche Album-Tags im Ordner – je Feld einen Wert fürs ganze Album wählen) --}}
@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
@endphp
<div class="flex min-h-full">
    {{-- zweite Leiste: alle Alben und Prüfungen --}}
    <aside class="sticky top-0 h-[calc(100vh-4rem)] w-60 shrink-0 border-r border-zinc-800 bg-zinc-950 px-3 py-4">
        <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Alben</div>
        @foreach (\App\Livewire\AlbumList::CHECKS as $key => $label)
            <button wire:click="$set('check', '{{ $key }}')"
                    @class(['flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-sm',
                            'bg-accent/10 text-accent font-medium' => $check === $key,
                            'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => $check !== $key])>
                @if ($key === '')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg>
                @elseif ($key === 'unvollstaendig')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 9 9h-9z"/></svg>
                @elseif ($key === 'sortiernamen')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 6h9M4 12h6M4 18h3M17 4v16m0 0-3-3m3 3 3-3"/></svg>
                @elseif ($key === 'serien')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="5" height="16" rx="1"/><rect x="10" y="4" width="5" height="16" rx="1"/><path d="m17 5 3.5 14"/></svg>
                @elseif ($key === 'sampler')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="7" y="7" width="13" height="13" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/></svg>
                @elseif (str_starts_with($key, 'cover-'))
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>
                @else
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0 0 18M15 4.5A9 9 0 0 1 15 19.5"/><circle cx="12" cy="12" r="2.5"/></svg>
                @endif
                <span class="min-w-0 flex-1 truncate leading-tight" title="{{ $label }}">{{ $label }}</span>
                @isset ($counts[$key])
                    <span @class(['shrink-0 rounded-full px-1.5 text-xs tabular-nums', 'bg-amber-500/15 text-amber-300' => $counts[$key] > 0, 'text-zinc-500' => $counts[$key] === 0])>{{ $fmt($counts[$key]) }}</span>
                @endisset
            </button>
        @endforeach
    </aside>

    <div class="min-w-0 flex-1">
    @if ($check === '')
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Alben</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }} in Inbox und Lib</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
            </div>
        </div>
    </header>

    @if ($message)
        <div class="mx-6 mt-4 rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    <table class="w-full table-fixed border-separate border-spacing-0 text-sm">
        <colgroup><col class="w-20"><col><col class="w-[28%]"><col class="w-20"><col class="w-20"><col class="w-24"><col class="w-48"></colgroup>
        <thead class="sticky top-[61px] z-10 bg-zinc-950 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
            <tr>
                <th class="border-b border-zinc-800 px-6 py-2"></th>
                <th class="border-b border-zinc-800 px-2 py-2">Album</th>
                <th class="border-b border-zinc-800 px-2 py-2">Album-Interpret</th>
                <th class="border-b border-zinc-800 px-2 py-2 text-right">Jahr</th>
                <th class="border-b border-zinc-800 px-2 py-2 text-right">Lieder</th>
                <th class="border-b border-zinc-800 px-2 py-2">Bereich</th>
                <th class="border-b border-zinc-800 px-2 py-2 pr-6"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($albums as $a)
                <tr wire:key="all-{{ $a->album_hash }}" class="hover:bg-zinc-900">
                    <td class="border-b border-zinc-800/70 py-2 pl-6 pr-2">
                        <img src="{{ \App\Support\Covers::url($a->cover, 300) }}" loading="lazy" alt="" class="cover-link size-10 rounded-md bg-zinc-800 object-cover" onerror="noCover(this)"
                             @click="$dispatch('open-cover-picker', { album: @js($a->album_hash) })" title="Cover ändern">
                    </td>
                    <td class="border-b border-zinc-800/70 px-2 py-2">
                        <div class="truncate font-medium" title="{{ $a->album }}">{{ $a->album ?: 'Ohne Album' }}</div>
                        <div class="truncate text-xs text-zinc-500" title="{{ $a->dir }}">{{ basename($a->dir) }}</div>
                    </td>
                    <td class="truncate border-b border-zinc-800/70 px-2 py-2 text-zinc-300" title="{{ $a->albumartist }}">{{ $a->albumartist }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-right tabular-nums text-zinc-400">{{ mb_substr((string) $a->year, 0, 4) }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-right tabular-nums text-zinc-300">{{ $fmt($a->tracks) }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-xs text-zinc-400">{{ \App\Support\Locations::LABELS[$a->location] ?? $a->location }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 pr-6 text-right">
                        <button class="mr-2 rounded px-1.5 py-0.5 text-xs text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" title="Cover setzen"
                                @click="$dispatch('open-cover-picker', { album: @js($a->album_hash) })">Cover</button>
                        @if (isset($split[$a->album_hash]))
                            <button wire:click="showSplit(@js($a->album_hash))" title="Uneinheitliche Album-Tags – Grund und Vorschlag ansehen"
                                    class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-medium text-amber-300 hover:bg-amber-500/25">auseinandergerissen</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Noch keine Alben – sie entstehen beim Einlesen aus den Ordnern.' }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($total > count($albums))
        <div class="py-6 text-center" x-intersect.margin.600px="$wire.more()">
            <button class="btn" wire:click="more">Mehr laden</button>
        </div>
    @endif
    @elseif ($check === 'unvollstaendig')
    @php
        $fmtTime = fn ($s) => $s === null ? '' : sprintf('%d:%02d', intdiv((int) $s, 60), (int) $s % 60);
        $locBadge = fn ($loc) => ['inbox' => 'bg-sky-500/15 text-sky-300', 'library' => 'bg-emerald-500/15 text-emerald-300', 'blackhole' => 'bg-violet-500/15 text-violet-300'][$loc] ?? 'bg-zinc-800 text-zinc-400';
    @endphp
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Unvollständig</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }} {{ $exempted ? 'ausgenommen (gewollt unvollständig)' : 'mit Lücken, fehlenden Titeln oder CDs' }}</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
            </div>
            <label class="flex cursor-pointer select-none items-center gap-2 text-sm text-zinc-300">
                <input type="checkbox" wire:model.live="exempted" class="size-4 rounded border-zinc-700 accent-[var(--color-accent)]"> Ausgenommene zeigen
            </label>
        </div>
    </header>
    <div class="max-w-5xl space-y-3 px-6 py-5">
        @if ($error)
            <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
        @elseif ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        @forelse ($albums as $a)
            @php $d = ($details['hash'] ?? null) === $a['hash'] ? $details : null; @endphp
            <section wire:key="inc-{{ $a['hash'] }}" @class(['rounded-lg border bg-zinc-900', 'border-accent/50' => $d, 'border-zinc-800' => ! $d])>
                <div class="flex cursor-pointer items-center gap-4 px-4 py-3 hover:bg-zinc-800/40" wire:click="toggle(@js($a['hash']))">
                    <img src="{{ \App\Support\Covers::url($a['cover'], 100) }}" loading="lazy" class="size-12 shrink-0 rounded-md bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $a['album'] ?: basename($a['dir']) }}</div>
                        <div class="truncate text-sm text-zinc-400">{{ $a['albumartist'] ?: 'Unbekannter Interpret' }}</div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$a['location']] }} · {{ $a['dir'] }}</div>
                    </div>
                    <div class="shrink-0 space-y-0.5 text-right text-xs text-amber-300">
                        @foreach ($a['discs'] as $disc => $x)
                            @if ($x['missing'])
                                <div>{{ count($a['discs']) > 1 ? 'CD '.$disc.': ' : '' }}{{ $x['have'] }} von {{ $x['total'] }} – es fehl{{ count($x['missing']) === 1 ? 't' : 'en' }} {{ implode(', ', array_slice($x['missing'], 0, 10)) }}{{ count($x['missing']) > 10 ? ' …' : '' }}</div>
                            @endif
                        @endforeach
                        @if ($a['missing_discs'])<div>es fehl{{ count($a['missing_discs']) === 1 ? 't CD' : 'en CDs' }} {{ implode(', ', $a['missing_discs']) }}</div>@endif
                        @if ($a['fetched'])<div class="text-zinc-500">Titelliste geholt</div>@endif
                    </div>
                    <svg @class(['size-4 shrink-0 text-zinc-500 transition', 'rotate-180' => $d]) fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                </div>

                @if ($d)
                    <div class="space-y-4 border-t border-zinc-800 px-4 py-4 text-sm">
                        {{-- Titelliste --}}
                        <div class="flex flex-wrap items-center gap-3">
                            <button class="btn" wire:click="fetchTracklist(@js($a['hash']))" wire:loading.attr="disabled" wire:target="fetchTracklist">
                                <span wire:loading.remove wire:target="fetchTracklist">{{ $d['tracklist'] ? 'Titelliste neu suchen' : 'Titelliste suchen' }}</span>
                                <span wire:loading wire:target="fetchTracklist">Suche bei MusicBrainz, Deezer, iTunes …</span>
                            </button>
                            @if ($d['release'])
                                <span class="text-xs text-zinc-400">Titelliste: <span class="text-zinc-200">{{ $d['release']['service'] }} – „{{ $d['release']['title'] }}“</span>{{ $d['release']['year'] ? ' ('.$d['release']['year'].')' : '' }} · {{ count($d['release']['tracks']) }} Titel · passt zu {{ $d['release']['matched'] }} von {{ $d['tracks'] }}</span>
                            @elseif ($d['tracklist'])
                                <span class="text-xs text-amber-300">Keine Titelliste passt sicher – bitte unten wählen.</span>
                            @else
                                <span class="text-xs text-zinc-500">Ohne Titelliste ist nur die Nummer bekannt, nicht der Titel.</span>
                            @endif
                            <span class="flex-1"></span>
                            @if ($exempted)
                                <button class="btn text-xs" wire:click="exempt(@js($a['hash']), false)">Wieder prüfen</button>
                            @else
                                <button class="btn text-xs" wire:click="exempt(@js($a['hash']), true)" title="Gewollt unvollständig – nicht mehr melden">Ausnehmen</button>
                            @endif
                        </div>

                        @if ($d['tracklist'] && count($d['tracklist']->candidates ?? []) > 0)
                            <details @if (! $d['release']) open @endif class="rounded-md border border-zinc-800 px-3 py-2">
                                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-zinc-500">Gefundene Titellisten ({{ count($d['tracklist']->candidates) }})</summary>
                                <div class="mt-2 space-y-1">
                                    @foreach ($d['tracklist']->candidates as $i => $c)
                                        @php $isChosen = $d['release'] && $d['release'] == $c; @endphp
                                        <div class="flex items-center gap-3 text-xs">
                                            <span class="w-24 shrink-0 text-zinc-500">{{ $c['service'] }}</span>
                                            <span class="min-w-0 flex-1 truncate">„{{ $c['title'] }}“ – {{ $c['artist'] }}{{ $c['year'] ? ' ('.$c['year'].')' : '' }} · {{ count($c['tracks']) }} Titel</span>
                                            <span @class(['shrink-0 tabular-nums', 'text-emerald-300' => $c['score'] >= \App\Support\IncompleteAlbums::SURE, 'text-zinc-500' => $c['score'] < \App\Support\IncompleteAlbums::SURE])>passt zu {{ $c['matched'] }} von {{ $d['tracks'] }}</span>
                                            @if ($isChosen)
                                                <span class="w-20 shrink-0 text-right text-accent">gewählt</span>
                                            @else
                                                <button class="w-20 shrink-0 text-right text-accent hover:underline" wire:click="chooseRelease(@js($a['hash']), {{ $i }})">diese nehmen</button>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if ($d['tracklist']->chosen !== null)
                                        <button class="text-xs text-zinc-500 hover:text-zinc-300" wire:click="chooseRelease(@js($a['hash']), null)">wieder automatisch wählen</button>
                                    @endif
                                </div>
                            </details>
                        @endif
                        @if ($d['tracklist']?->errors)<p class="text-xs text-zinc-500">{{ $d['tracklist']->errors }}</p>@endif

                        {{-- Titelanzahl --}}
                        @if ($d['totals'] || $d['trimTo'])
                            <div class="flex flex-wrap items-center gap-3 rounded-md bg-sky-500/10 px-3 py-2 text-sky-200">
                                <span class="flex-1">
                                    @if ($d['totals'])
                                        Laut Titelliste stimmt die Titelanzahl nicht:
                                        {{ collect($d['totals']['discs'] ?? [])->map(fn ($c, $disc) => ($d['multi'] || count($d['totals']['discs']) > 1 ? 'CD '.$disc.': ' : '').($c['from'] ?? '–').' → '.$c['to'])->implode(' · ') }}
                                        @if (isset($d['totals']['disc_total']))· CDs: {{ $d['totals']['disc_total']['from'] ?? '–' }} → {{ $d['totals']['disc_total']['to'] }}@endif
                                    @else
                                        Es fehlen nur Nummern am Ende – vielleicht ist die Titelanzahl falsch:
                                        {{ collect($d['trimTo'])->map(fn ($n, $disc) => (count($d['discs']) > 1 ? 'CD '.$disc.': ' : '').$n)->implode(' · ') }} Titel?
                                    @endif
                                </span>
                                <button class="btn shrink-0 text-xs" wire:click="fixTotals(@js($a['hash']))">Titelanzahl korrigieren</button>
                            </div>
                        @endif

                        {{-- fehlende Plätze --}}
                        <div class="space-y-2">
                            @foreach ($d['slots'] as $slot)
                                <div wire:key="slot-{{ $slot['disc'] }}-{{ $slot['n'] }}" class="rounded-md border border-zinc-800">
                                    <div class="flex items-baseline gap-3 border-b border-zinc-800/70 px-3 py-2">
                                        <span class="w-14 shrink-0 tabular-nums text-zinc-500">{{ $d['multi'] ? $slot['disc'].'-' : '' }}{{ $slot['n'] }}</span>
                                        <span class="min-w-0 flex-1">
                                            @if ($slot['title'])<span class="font-medium">{{ $slot['title'] }}</span> <span class="text-zinc-400">– {{ $slot['artist'] }}</span>@else<span class="italic text-zinc-500">Titel unbekannt – Titelliste suchen</span>@endif
                                        </span>
                                        <span class="text-xs tabular-nums text-zinc-500">{{ $fmtTime($slot['duration']) }}</span>
                                    </div>
                                    <div class="divide-y divide-zinc-800/60">
                                        @forelse ($slot['candidates'] as $c)
                                            @php $t = $c['track']; @endphp
                                            <div class="flex flex-wrap items-center gap-3 px-3 py-1.5 text-xs">
                                                <span class="shrink-0 rounded px-1.5 py-0.5 font-medium {{ $locBadge($t->location) }}">{{ \App\Support\Locations::LABELS[$t->location] }}</span>
                                                <span class="min-w-0 flex-1 truncate" title="{{ $t->path }}">{{ $t->title ?: $t->filename }} – {{ $t->artist }} <span class="text-zinc-500">· {{ $t->album ?: 'ohne Album' }}</span></span>
                                                <span class="shrink-0 text-zinc-500">{{ $c['reason'] }}</span>
                                                <span class="shrink-0 text-zinc-500">{{ $t->qualityLabel() }} · {{ $t->durationLabel() }}</span>
                                                @if ($c['diff'] !== null)
                                                    <span @class(['shrink-0', 'text-emerald-300' => $c['exact'], 'text-amber-300' => ! $c['exact']])>{{ $c['exact'] ? 'gleiche Länge' : 'Länge '.($c['diff'] > 0 ? '+' : '').$c['diff'].' s – andere Fassung?' }}</span>
                                                @endif
                                                @if ($c['blackhole'])
                                                    <button class="btn shrink-0 py-0.5 text-xs" wire:click="toInbox({{ $t->id }})" @disabled($t->move_to === 'inbox')>{{ $t->move_to === 'inbox' ? 'für die Inbox vorgemerkt' : 'erst in die Inbox holen' }}</button>
                                                @else
                                                    <button class="btn shrink-0 py-0.5 text-xs" wire:click="planTrack({{ $t->id }}, @js($a['hash']), {{ $slot['disc'] }}, {{ $slot['n'] }}, 'copy')">Kopieren</button>
                                                    @if ($c['alone'])
                                                        <button class="btn-primary shrink-0 py-0.5 text-xs" wire:click="planTrack({{ $t->id }}, @js($a['hash']), {{ $slot['disc'] }}, {{ $slot['n'] }}, 'move')" title="Steht allein – zerreißt kein Album">Verschieben</button>
                                                    @endif
                                                @endif
                                            </div>
                                        @empty
                                            <div class="px-3 py-1.5 text-xs text-zinc-500">{{ $slot['title'] ? 'Bei uns nicht gefunden.' : 'Bei uns kein Lied aus diesem Album mit dieser Nummer.' }}</div>
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach
                            @if (! $d['slots'] && ! $d['plans']->count())
                                <p class="text-xs text-zinc-500">Laut Titelliste fehlt kein Titel.</p>
                            @endif
                        </div>

                        {{-- Vormerkungen --}}
                        @if ($d['plans']->count())
                            <div>
                                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">Vorgemerkt – ausgeführt beim Schreiben</div>
                                @foreach ($d['plans'] as $p)
                                    <div class="flex items-center gap-3 py-1 text-xs">
                                        <span class="w-14 shrink-0 tabular-nums text-zinc-500">{{ $d['multi'] ? ($p->values['disc'] ?? 1).'-' : '' }}{{ $p->values['track'] }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $p->track?->title }} – {{ $p->track?->artist }} <span class="text-zinc-500">· {{ $p->action === 'move' ? 'verschieben' : 'kopieren' }} aus {{ \App\Support\Locations::LABELS[$p->track?->location] ?? '?' }}</span></span>
                                        <button class="text-zinc-500 hover:text-zinc-200" wire:click="unplan({{ $p->id }})">Aufheben</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : ($exempted ? 'Kein Album ausgenommen.' : 'Alle Alben sind vollständig.') }}</div>
        @endforelse
        @if ($total > count($albums))
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    </div>
    @elseif ($check === 'sortiernamen')
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Sortiernamen</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }}: Sortiername in der Datei weicht ab und ist nicht eindeutig</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
            </div>
            <button class="btn-primary" wire:click="fixSortNames(null)" @disabled(! $total)
                    data-confirm-title="Sortiernamen übernehmen" data-confirm-ok="Alle übernehmen" data-confirm="Bei allen {{ $fmt($total) }} Alben die Sortiernamen nach der Regel übernehmen?">Alle übernehmen</button>
        </div>
    </header>
    <div class="max-w-5xl space-y-3 px-6 py-5">
        @if ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        @forelse ($albums as $a)
            <section wire:key="sort-{{ $a['hash'] }}" class="rounded-lg border border-zinc-800 bg-zinc-900">
                <div class="flex items-center gap-4 border-b border-zinc-800 px-4 py-2.5">
                    <img src="{{ \App\Support\Covers::url($a['cover'], 100) }}" loading="lazy" class="size-12 shrink-0 rounded-md bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $a['album'] ?: basename($a['dir']) }}</div>
                        <div class="truncate text-sm text-zinc-400">{{ $a['albumartist'] ?: 'Unbekannter Interpret' }}</div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$a['location']] }} · {{ $a['dir'] }}</div>
                    </div>
                    <button class="btn shrink-0" wire:click="fixSortNames(@js($a['hash']))">Übernehmen</button>
                </div>
                <div class="divide-y divide-zinc-800/60 text-sm">
                    @foreach ($a['rows'] as $r)
                        <div class="flex flex-wrap items-baseline gap-3 px-4 py-2">
                            <span class="w-44 shrink-0 text-xs text-zinc-500">{{ \App\Models\Track::EDITABLE[$r['field']] }}</span>
                            <span class="min-w-0 flex-1">{{ $r['text'] }}</span>
                            <span><span class="text-zinc-500 line-through">{{ $r['current'] }}</span> → {{ $r['expected'] ?? 'keiner nötig' }}</span>
                            <span class="w-16 shrink-0 text-right text-xs text-zinc-500">{{ $r['count'] }} {{ $r['count'] === 1 ? 'Lied' : 'Lieder' }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Alle Sortiernamen passen.' }}</div>
        @endforelse
        @if ($albums)
            <p class="text-xs text-zinc-500">Fehlende und nur umgestellte Sortiernamen trägt MusicWiz selbst ein. Soll ein abweichender Wert bleiben: unter Interpreten › Sortiernamen „behalten“.</p>
        @endif
        @if ($total > count($albums))
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    </div>
    @elseif ($check === 'serien')
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Serien</h1>
                <p class="text-xs text-zinc-500">{{ $allSeries ? $fmt($total).' '.($total === 1 ? 'Serie' : 'Serien').', davon '.$fmt($open).' mit Abweichungen' : $fmt($total).' '.($total === 1 ? 'Serie' : 'Serien').' mit Alben, die vom gemeinsamen Muster abweichen' }}</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Serie oder Album suchen …" class="field pl-8">
            </div>
            <label class="flex items-center gap-2 text-sm text-zinc-300" title="Auch Serien zeigen, die schon einheitlich sind – um ein Muster festzulegen">
                <input type="checkbox" wire:model.live="allSeries" class="size-4 rounded border-zinc-600 accent-[var(--color-accent)]"> Alle Serien zeigen
            </label>
            <button class="btn-primary" wire:click="fixSeries(null)" @disabled(! $open)
                    data-confirm-title="Serien" data-confirm-ok="Alle übernehmen" data-confirm="Bei allen {{ $fmt($open) }} Serien mit Abweichungen das Muster übernehmen?">Alle übernehmen</button>
        </div>
    </header>
    <div class="max-w-5xl space-y-4 px-6 py-5">
        @if ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        @if ($error)
            <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
        @endif
        <p class="text-xs text-zinc-500">Alben, deren Name ohne Zahlen, Groß/klein und Satzzeichen gleich ist, gehören zu einer Serie. Muster: fester Text wie bei den meisten Alben der Serie, „Vol“/„Vol.“/„Volume“ einheitlich, Jahre vierstellig („1/99“ → „1/1999“). Nur in der Datenbank (unter Änderungen zurücksetzbar); die Ordner ziehen beim Schreiben mit.</p>
        @forelse ($series as $s)
            <section wire:key="series-{{ $s['key'] }}" class="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
                <div class="flex flex-wrap items-center gap-3 border-b border-zinc-800 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $s['pattern'] }}</div>
                        <div class="text-xs text-zinc-500">{{ $s['albums'] }} Alben · {{ $s['changes'] ? count($s['changes']).' '.(count($s['changes']) === 1 ? 'weicht' : 'weichen').' ab' : 'alle folgen dem Muster' }}</div>
                        {{-- Muster: eins der vorkommenden wählen oder ein eigenes festlegen (gilt dauerhaft, auch für neue Alben) --}}
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs" x-data="{ edit: false, value: @js($s['pattern']) }">
                            <span class="text-zinc-500">Muster:</span>
                            @if (count($s['patterns']) > 1 || $s['custom'])
                                @foreach ($s['patterns'] as $p)
                                    <button type="button" wire:click="chooseSeries(@js($s['key']), @js($p['pattern']))"
                                            @class(['rounded border px-2 py-0.5', 'border-accent/60 bg-accent/15 text-accent' => $p['pattern'] === $s['pattern'],
                                                    'border-zinc-700 text-zinc-400 hover:text-zinc-100' => $p['pattern'] !== $s['pattern']])>{{ $p['pattern'] }} <span class="text-zinc-500">({{ $p['count'] }})</span></button>
                                @endforeach
                            @endif
                            @if ($s['custom'])
                                <span class="rounded border border-accent/60 bg-accent/15 px-2 py-0.5 text-accent">{{ $s['pattern'] }} <span class="text-zinc-500">(eigenes)</span></span>
                            @endif
                            <button type="button" x-show="!edit" @click="edit = true; $nextTick(() => $refs.input.focus())" class="rounded px-1.5 py-0.5 text-zinc-400 underline decoration-dotted hover:text-zinc-100">eigenes Muster …</button>
                            @if ($s['stored'] !== null)
                                <button type="button" wire:click="resetSeries(@js($s['key']))" class="rounded px-1.5 py-0.5 text-zinc-500 hover:text-zinc-200" title="Gespeichertes Muster entfernen – wieder das häufigste">zurücksetzen</button>
                            @endif
                            <form x-show="edit" x-cloak class="flex w-full items-center gap-1.5" @submit.prevent="$wire.chooseSeries(@js($s['key']), value); edit = false">
                                <input x-ref="input" x-model="value" class="field min-w-0 flex-1 py-1 text-xs" @keydown.escape.stop="edit = false" placeholder="z. B. Viva Hits, Vol. {Nr}">
                                <button class="btn-primary py-0.5 text-xs">Festlegen</button>
                                <button type="button" class="btn py-0.5 text-xs" @click="edit = false">Abbrechen</button>
                            </form>
                            <span x-show="edit" x-cloak class="w-full text-zinc-500">{{ $s['placeholders'] }} Platzhalter der Reihe nach: {Nr} – Zahl wie im Album, {Jahr} – vierstellig. Feste Zahlen (wie „20“ in „20 Top Hits“) direkt hinschreiben.</span>
                            @if ($s['problem'])
                                <span class="w-full text-amber-300">Gespeichertes Muster „{{ $s['stored'] }}“ passt nicht mehr: {{ $s['problem'] }}</span>
                            @endif
                        </div>
                    </div>
                    <button class="btn shrink-0 py-0.5 text-xs" wire:click="exemptSeries(@js($s['key']), true)" title="Keine Serie – nicht mehr vorschlagen">Keine Serie</button>
                    @if ($s['changes'])<button class="btn-primary shrink-0 py-0.5 text-xs" wire:click="fixSeries(@js($s['key']))">Übernehmen</button>@endif
                </div>
                <ul class="divide-y divide-zinc-800/70 text-sm">
                    @foreach ($s['changes'] as $c)
                        <li wire:key="series-{{ $s['key'] }}-{{ $c['hash'] }}" class="flex items-center gap-3 px-4 py-1.5">
                            <img src="{{ \App\Support\Covers::url($c['cover'], 64) }}" loading="lazy" class="size-8 shrink-0 rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                            <div class="min-w-0 flex-1">
                                <div class="truncate"><span class="text-zinc-500 line-through">{{ $c['from'] }}</span> → <span class="font-medium">{{ $c['to'] }}</span></div>
                                <div class="truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$c['location']] }} · {{ $c['dir'] }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($s['others'])
                    <div class="border-t border-zinc-800 px-4 py-1.5 text-xs text-zinc-500" title="{{ implode(' · ', $s['others']) }}">Anderer Aufbau, nicht angeglichen: {{ implode(' · ', array_slice($s['others'], 0, 4)) }}{{ count($s['others']) > 4 ? ' …' : '' }}</div>
                @endif
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : ($allSeries ? 'Keine Serien gefunden.' : 'Alle Serien folgen ihrem Muster – „Alle Serien zeigen“, um eins festzulegen.') }}</div>
        @endforelse
        @if ($total > count($series))
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
        @if ($exemptedSeries->isNotEmpty())
            <details class="text-sm">
                <summary class="cursor-pointer text-zinc-500 hover:text-zinc-300">{{ $exemptedSeries->count() }} als „keine Serie“ ausgenommen</summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($exemptedSeries as $s)
                        <li wire:key="series-x-{{ $s['key'] }}" class="flex items-center gap-3 rounded-md bg-zinc-900 px-3 py-1.5">
                            <span class="min-w-0 flex-1 truncate">{{ $s['pattern'] }} <span class="text-xs text-zinc-500">({{ $s['albums'] }} Alben)</span></span>
                            <button class="btn shrink-0 py-0.5 text-xs" wire:click="exemptSeries(@js($s['key']), false)">Wieder aufnehmen</button>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
    @elseif ($check === 'sampler')
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Sampler-Kennzeichnung</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }}: Sampler-Flag und „Various Artists“ passen nicht zu den Interpreten</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
            </div>
            <button class="btn-primary" wire:click="fixSampler(null)" @disabled(! $total)
                    data-confirm-title="Sampler-Kennzeichnung" data-confirm-ok="Alle richten" data-confirm="Bei allen {{ $fmt($total) }} Alben die Sampler-Kennzeichnung richten?">Alle richten</button>
        </div>
    </header>
    <div class="max-w-5xl space-y-3 px-6 py-5">
        @if ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        @forelse ($albums as $a)
            @php $missing = $a['kind'] === 'missing'; @endphp
            <section wire:key="sampler-{{ $a['hash'] }}" class="flex items-center gap-4 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
                <img src="{{ \App\Support\Covers::url($a['cover'], 100) }}" loading="lazy" class="size-12 shrink-0 rounded-md bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-medium">{{ $a['album'] ?: basename($a['dir']) }}</span>
                        <span @class(['shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium', 'bg-amber-500/15 text-amber-300' => $missing, 'bg-sky-500/15 text-sky-300' => ! $missing])>{{ \App\Support\SamplerFlags::KINDS[$a['kind']] }}</span>
                    </div>
                    <div class="truncate text-sm text-zinc-400">Album-Interpret: {{ $a['albumartist'] ?: '–' }}</div>
                    <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$a['location']] }} · {{ $a['dir'] }}</div>
                </div>
                <div class="shrink-0 space-y-0.5 text-right text-xs text-zinc-400">
                    <div>{{ $a['tracks'] }} Lieder · {{ $missing ? $a['artists'].' Interpreten' : 'alle von '.$a['artist'] }}</div>
                    <div>Flag bei {{ $a['flagged'] }} · „Various Artists“ bei {{ $a['va'] }}</div>
                    <div class="text-zinc-500">→ {{ $missing ? 'Flag setzen, Album-Interpret „Various Artists“' : 'Flag entfernen'.($a['va'] ? ', Album-Interpret „'.$a['artist'].'“' : '') }}</div>
                </div>
                <button class="btn shrink-0" wire:click="fixSampler(@js($a['hash']))">{{ $missing ? 'Als Sampler kennzeichnen' : 'Kennzeichnung entfernen' }}</button>
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Alle Sampler sind richtig gekennzeichnet.' }}</div>
        @endforelse
        @if ($albums)
            <p class="text-xs text-zinc-500">Grundlage sind die Interpreten der Lieder (Gäste zählen nicht). Der Interpret der Lieder bleibt; nur in der Datenbank, unter Änderungen zurücksetzbar.</p>
        @endif
        @if ($total > count($albums))
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    </div>
    @elseif (str_starts_with($check, 'cover-'))
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">{{ \App\Livewire\AlbumList::CHECKS[$check] }}</h1>
                <p class="text-xs text-zinc-500">
                    {{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }} –
                    {{ $check === 'cover-klein' ? 'das Cover, das Navidrome zeigt (Bild im Ordner, sonst eingebettet), ist kleiner als '.$minSize.' px' : 'eingebettete Cover und Bild im Ordner passen nicht zusammen' }}
                </p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
            </div>
        </div>
    </header>

    <div class="max-w-5xl space-y-4 px-6 py-5">
        @if ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        @if ($unread)
            <div class="rounded-md bg-sky-500/10 px-3 py-2 text-sm text-sky-300">
                {{ $fmt($unread) }} {{ $unread === 1 ? 'Lied hat' : 'Lieder haben' }} noch keine Cover-Daten – ihre Alben werden erst nach dem nächsten Einlesen von Inbox bzw. Lib geprüft (dabei wird einmalig alles neu gelesen).
            </div>
        @endif
        @forelse ($albums as $a)
            <section wire:key="cover-{{ $check }}-{{ $a['hash'] }}" class="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
                <div class="flex items-center gap-3 border-b border-zinc-800 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium" title="{{ $a['album'] }}">{{ $a['album'] ?: 'Ohne Album' }}</div>
                        <div class="truncate text-sm text-zinc-400">{{ $a['artist'] }}</div>
                        <div class="truncate text-xs text-zinc-500" title="{{ $a['dir'] }}">{{ $a['dir'] }}</div>
                    </div>
                    <span class="shrink-0 rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] font-medium text-zinc-400">{{ \App\Support\Locations::LABELS[$a['location']] }}</span>
                    <span class="shrink-0 text-xs text-zinc-500">{{ $fmt($a['tracks']) }} {{ $a['tracks'] === 1 ? 'Lied' : 'Lieder' }}</span>
                </div>
                <div class="space-y-3 px-4 py-3">
                    <div class="flex items-baseline gap-3">
                        <span class="w-28 shrink-0 text-xs font-medium text-zinc-300">Grund</span>
                        <ul class="flex-1 space-y-0.5 text-sm text-amber-300">
                            @foreach ($a['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                        </ul>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="w-28 shrink-0 pt-1 text-xs text-zinc-500">Fassungen</span>
                        <div class="flex flex-1 flex-wrap gap-3">
                            @foreach ($a['variants'] as $v)
                                @php $isBest = $a['best'] && $a['best'] == $v; $tooSmall = min($v['width'], $v['height']) < $minSize; @endphp
                                <figure @class(['w-28 rounded-md border p-1.5 text-center', 'border-accent/60 bg-accent/10' => $isBest, 'border-zinc-800' => ! $isBest])>
                                    <img src="{{ match ($v['kind']) { 'folder' => route('folder.image', [$v['image'], 300, 'v' => $v['version']]), 'new' => route('cover.image', [$v['hash'], 300]), default => \App\Support\Covers::url($v['track'], 300, true) } }}" loading="lazy" alt=""
                                         class="aspect-square w-full rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'">
                                    <figcaption class="mt-1 text-[11px] leading-tight text-zinc-400">
                                        <div class="font-medium text-zinc-300">Motiv {{ $v['motif'] + 1 }}</div>
                                        <div>{{ $v['kind'] === 'folder' ? $v['name'] : ($v['kind'] === 'new' ? 'neu gesetzt' : 'eingebettet').' · '.$fmt($v['count']).' '.($v['count'] === 1 ? 'Lied' : 'Lieder') }}</div>
                                        <div @class(['tabular-nums', 'text-red-400' => $tooSmall])>{{ $v['width'] }}×{{ $v['height'] }} px</div>
                                    </figcaption>
                                </figure>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <span class="w-28 shrink-0 text-xs text-zinc-500">Vorschlag</span>
                        <div class="flex-1 text-sm">
                            @if ($a['suggest'])
                                <span class="text-accent">{{ ucfirst($a['suggest']) }}</span>
                                <span class="text-xs text-zinc-400">– {{ $a['why'] }}</span>
                            @else
                                <span class="text-xs italic text-zinc-400">{{ ucfirst($a['why']) }}</span>
                            @endif
                        </div>
                        @if ($check === 'cover-verschieden')
                            <button class="btn shrink-0 py-0.5 text-xs" @click="$dispatch('open-cover-picker', { album: @js($a['hash']) })">Cover wählen …</button>
                        @endif
                    </div>
                    @if ($check === 'cover-klein')
                        {{-- Vorschläge und URL direkt hier – im Netz gesucht wird erst auf Knopfdruck --}}
                        <div class="flex items-start gap-3 border-t border-zinc-800/60 pt-3">
                            <span class="w-28 shrink-0 pt-1 text-xs text-zinc-500">Neues Cover</span>
                            <div class="min-w-0 flex-1">
                                <livewire:cover-picker :album="$a['hash']" :inline="true" :min="$minSize" wire:key="picker-{{ $a['hash'] }}" />
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : ($check === 'cover-klein' ? 'Alle Cover sind groß genug.' : 'Alle Alben haben einheitliche Cover.') }}</div>
        @endforelse
        @if ($albums)
            <p class="text-xs text-zinc-500">Gleiches Motiv: Bilder werden über einen Fingerabdruck verglichen – auch in anderer Größe oder Kompression. Vorschläge lassen sich noch nicht ausführen: Cover stecken in den Dateien, und in Dateien schreibt MusicWiz noch nicht.</p>
        @endif
        @if ($total > count($albums))
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    </div>
    @else
<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-4">
        <div class="mr-2">
            <h1 class="text-lg font-semibold leading-tight">Auseinandergerissen</h1>
            <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album würde' : 'Alben würden' }} in Navidrome auseinanderfallen – uneinheitliche Tags im Ordner</p>
        </div>
        <div class="relative min-w-60 flex-1">
            <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
        </div>
    </div>
</header>

<div class="max-w-5xl space-y-4 px-6 py-5">
    @if ($error)
        <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
    @elseif ($message)
        <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    @forelse ($albums as $a)
        <section wire:key="album-{{ $a['hash'] }}" class="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
            <div class="flex items-center gap-3 border-b border-zinc-800 px-4 py-2.5">
                <img src="{{ \App\Support\Covers::url($a['cover'], 300) }}" loading="lazy" alt="" class="cover-link size-12 shrink-0 rounded-md bg-zinc-800 object-cover" onerror="noCover(this)"
                     @click="$dispatch('open-cover-picker', { album: @js($a['hash']) })" title="Cover ändern">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium" title="{{ $a['album'] }}">{{ $a['album'] ?: 'Ohne Album' }}</div>
                    <div class="truncate text-sm text-zinc-400">{{ $a['artist'] }}</div>
                    <div class="truncate text-xs text-zinc-500" title="{{ $a['dir'] }}">{{ $a['dir'] }}</div>
                </div>
                <span class="shrink-0 rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] font-medium text-zinc-400">{{ \App\Support\Locations::LABELS[$a['location']] }}</span>
                <span class="shrink-0 text-xs text-zinc-500">{{ $fmt($a['tracks']) }} {{ $a['tracks'] === 1 ? 'Lied' : 'Lieder' }}</span>
            </div>
            <div class="divide-y divide-zinc-800/60">
                @foreach ($a['issues'] as $field => $issue)
                    <div class="space-y-2 px-4 py-3">
                        <div class="flex items-baseline gap-3">
                            <span class="w-44 shrink-0 text-xs font-medium text-zinc-300">{{ \App\Support\SplitAlbums::FIELDS[$field] }}</span>
                            <span class="flex-1 text-sm text-amber-300">{{ $issue['reason'] }}</span>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-44 shrink-0 pt-1 text-xs text-zinc-500">Werte im Album</span>
                            <div class="flex flex-1 flex-wrap gap-1.5">
                                @foreach ($issue['options'] as $o)
                                    <button wire:click="align(@js($a['hash']), '{{ $field }}', @js($o['value']))"
                                            @disabled($o['value'] === '' && ! in_array($field, \App\Support\SplitAlbums::MAY_BE_EMPTY, true))
                                            title="{{ $o['value'] === '' ? 'Feld bei allen Liedern leeren' : 'Für alle Lieder des Albums übernehmen' }}{{ $field === 'albumartist' && $o['value'] === \App\Support\NameRules::VARIOUS ? ' (setzt auch das Sampler-Flag)' : '' }}"
                                            class="rounded-md border border-zinc-700 px-2 py-0.5 text-left text-sm text-zinc-300 hover:border-zinc-500 hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50">
                                        @if ($o['value'] === '')<span class="italic text-zinc-500">leer</span>@else{{ $o['value'] }}@endif
                                        @if ($o['derived'])<span class="text-xs text-zinc-500">(aus Interpret)</span>@endif
                                        @if ($o['count'])<span class="text-xs tabular-nums text-zinc-500">· {{ $fmt($o['count']) }}</span>@else<span class="text-xs text-zinc-500">· Sampler</span>@endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-44 shrink-0 text-xs text-zinc-500">Vorschlag</span>
                            @if ($issue['suggest'] !== null)
                                <button class="btn shrink-0 border-accent/60 bg-accent/10 py-0.5 text-accent hover:bg-accent/20"
                                        wire:click="align(@js($a['hash']), '{{ $field }}', @js($issue['suggest']))"
                                        title="Für alle Lieder des Albums übernehmen">
                                    „{{ $issue['suggest'] === '' ? 'leer' : $issue['suggest'] }}“ übernehmen
                                </button>
                                <span class="text-xs text-zinc-400">{{ $issue['why'] }}</span>
                            @else
                                <span class="text-xs italic text-zinc-400">{{ $issue['why'] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Alle Alben sind einheitlich getaggt.' }}</div>
    @endforelse
    @if ($albums)
        <p class="text-xs text-zinc-500">Zahl hinter dem Wert: Lieder mit diesem Wert. Ein Klick auf einen Wert oder den Vorschlag setzt ihn für alle Lieder des Albums (nur in der Datenbank, steht danach unter Änderungen).</p>
    @endif

    @if ($total > count($albums))
        <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
    @endif
</div>
    @endif
    </div>
</div>
