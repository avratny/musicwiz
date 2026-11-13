{{-- Sonstiges: Leiste mit Prüfungen; „Zeichen & Schreibweise“ – kaputte Zeichen, sichere Reparatur, Vorschläge von Claude oder eigene
     Schreibweise, Schreibweisen zum Prüfen mit „Passt so“;
     „Jahr als Datum“ – nur das Jahr behalten; „Duplikate“ – wer bleibt, wer geht (nur vormerken);
     Genre (fehlt, weicht ab, uneinheitlich) – Vorschlag, Alternativen oder eigenes Genre, Sampler je Lied;
     „Nicht lesbar“ – neu lesen bzw. Einträge aus der Datenbank entfernen --}}
@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $fieldLabels = ['title' => 'Titel', 'artist' => 'Interpret', 'artists' => 'Interpreten einzeln', 'album' => 'Album', 'albumartist' => 'Album-Interpret', 'albumartists' => 'Album-Interpreten einzeln', 'genre' => 'Genre'];
    $kindStyle = ['lost' => 'bg-red-500/15 text-red-300', 'mojibake' => 'bg-amber-500/15 text-amber-300', 'invisible' => 'bg-sky-500/15 text-sky-300', 'nfd' => 'bg-sky-500/15 text-sky-300',
        'bracket' => 'bg-zinc-700/60 text-zinc-300', 'dot' => 'bg-zinc-700/60 text-zinc-300', 'hyphen' => 'bg-zinc-700/60 text-zinc-300'];
@endphp
<div class="flex min-h-full">
    {{-- zweite Leiste --}}
    <aside class="sticky top-0 h-[calc(100vh-4rem)] w-60 shrink-0 border-r border-zinc-800 bg-zinc-950 px-3 py-4">
        <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Sonstiges</div>
        @foreach (\App\Livewire\MiscPage::VIEWS as $key => $label)
            <button wire:click="$set('view', '{{ $key }}')"
                    @class(['flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-sm',
                            'bg-accent/10 text-accent font-medium' => $view === $key,
                            'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => $view !== $key])>
                @if ($key === 'replaygain')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>
                @elseif ($key === 'duplikate')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                @elseif (str_starts_with($key, 'genre_'))
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 2H2v10l9.3 9.3a1 1 0 0 0 1.4 0l8.6-8.6a1 1 0 0 0 0-1.4z"/><circle cx="7" cy="7" r="1.5"/></svg>
                @elseif ($key === 'fingerabdruck')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M3 12h2l2-6 3 12 3-9 2 6 2-3h4"/></svg>
                @elseif ($key === 'kaputt')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><path d="m3 3 18 18"/></svg>
                @elseif ($key === 'nicht_lesbar')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M10 13l4 4m0-4-4 4"/></svg>
                @elseif ($key === 'jahr')
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                @else
                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 7V5h16v2M9 19h6M12 5v14"/><path d="m17 14 4 4m0-4-4 4"/></svg>
                @endif
                <span class="min-w-0 flex-1 truncate leading-tight" title="{{ $label }}">{{ $label }}</span>
                <span @class(['shrink-0 rounded-full px-1.5 text-xs tabular-nums', 'bg-amber-500/15 text-amber-300' => $counts[$key] > 0, 'text-zinc-500' => $counts[$key] === 0])>{{ $fmt($counts[$key]) }}</span>
            </button>
        @endforeach
    </aside>

    <div class="min-w-0 flex-1">
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">{{ \App\Livewire\MiscPage::VIEWS[$view] }}</h1>
                @if ($view === 'replaygain')
                    <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }} · {{ $fmt($tracks) }} {{ $tracks === 1 ? 'Lied' : 'Lieder' }} ohne ReplayGain in Inbox und Lib</p>
                @elseif ($view === 'duplikate')
                    <p class="text-xs text-zinc-500">{{ $fmt($open) }} offen{{ $total > $open ? ', '.$fmt($total - $open).' vorgemerkt' : '' }} · gleiche Lieder in Inbox und Lib</p>
                @elseif (isset($check))
                    <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Eintrag' : 'Einträge' }} · {{ ['missing' => 'Lieder ohne Genre', 'mismatch' => 'Genre passt nicht zum Interpreten', 'mixed' => 'Interpreten-Alben mit verschiedenen Genres'][$check] }} in Inbox und Lib</p>
                @elseif ($view === 'fingerabdruck')
                    <p class="text-xs text-zinc-500">{{ $fmt($counts['fingerabdruck']) }} {{ $counts['fingerabdruck'] === 1 ? 'Lied' : 'Lieder' }} ohne Fingerabdruck</p>
                @elseif ($view === 'kaputt')
                    <p class="text-xs text-zinc-500">{{ $fmt($open) }} offen{{ $total > $open ? ', '.$fmt($total - $open).' vorgemerkt' : '' }} · Dateien ohne Audio (Länge 0) in Inbox und Lib</p>
                @elseif ($view === 'nicht_lesbar')
                    <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Datei' : 'Dateien' }}, die beim Einlesen nicht gelesen werden konnten</p>
                @elseif ($view === 'jahr')
                    <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }} mit mehr als der Jahreszahl im Jahr, in Inbox und Lib</p>
                @else
                    <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Wert' : 'Werte' }} mit kaputten Zeichen oder zum Prüfen in Inbox und Lib</p>
                @endif
            </div>
            @if ($view !== 'fingerabdruck')
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="{{ ['jahr' => 'Album, Interpret, Ordner, Jahr suchen …', 'genre_fehlt' => 'Album, Interpret, Titel oder Ordner suchen …', 'genre_abweichend' => 'Album, Interpret, Titel, Genre suchen …', 'genre_uneinheitlich' => 'Album, Interpret, Genre suchen …', 'duplikate' => 'Interpret, Titel oder Ordner suchen …', 'replaygain' => 'Album, Interpret oder Ordner suchen …', 'nicht_lesbar' => 'Pfad oder Fehler suchen …', 'kaputt' => 'Titel, Interpret, Album oder Pfad suchen …'][$view] ?? 'Wert suchen …' }}" class="field pl-8">
            </div>
            @endif
            @if ($view === 'replaygain')
                <button class="btn-primary" wire:click="measure(null)" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('replaygain')" @disabled(! $total)
                        data-confirm-title="ReplayGain messen" data-confirm-ok="Messen" data-confirm="Alle {{ $fmt($total) }} Alben im Hintergrund messen? Jede Datei wird einmal komplett gelesen – das dauert.">Alle messen</button>
            @elseif ($view === 'duplikate')
                <button class="btn-primary" wire:click="planDuplicates(null)" @disabled(! $open)
                        data-confirm-title="Duplikate" data-confirm-ok="Vormerken" data-confirm="Bei allen {{ $fmt($open) }} offenen Gruppen den Vorschlag vormerken? Ersetzt wird über Albumgrenzen nur, was der Fingerabdruck bestätigt – der Rest bleibt zum einzelnen Vormerken. Ausgeführt wird erst beim Schreiben.">Alle vormerken</button>
            @elseif (isset($check) && $check === 'missing')
                <button class="btn-primary" wire:click="takeGenreSuggestions(null)" @disabled(! $suggested)
                        data-confirm-title="Genre fehlt" data-confirm-ok="Übernehmen" data-confirm="Bei allen {{ $fmt($suggested) }} Einträgen mit Vorschlag jeweils den ersten übernehmen? Nur in der Datenbank, unter Änderungen zurücksetzbar.">Alle Vorschläge übernehmen</button>
            @elseif ($view === 'kaputt')
                <button class="btn-primary" wire:click="trashBroken(null)" @disabled(! $open)
                        data-confirm-title="Kaputte Lieder" data-confirm-ok="Vormerken" data-confirm="Alle {{ $fmt($open) }} kaputten Lieder für den Papierkorb vormerken? Ausgeführt wird beim Schreiben.">Alle in den Papierkorb</button>
            @elseif ($view === 'nicht_lesbar')
                <button class="btn" wire:click="rereadErrors" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('scan')" @disabled(! $counts['nicht_lesbar'])
                        title="Die betroffenen Bereiche einlesen – nicht lesbare Dateien werden dabei immer neu versucht">Neu lesen</button>
                <button class="btn text-red-300" wire:click="removeErrors" @disabled(! $counts['nicht_lesbar'])
                        data-confirm-title="Nicht lesbar" data-confirm-ok="Entfernen" data-confirm="Alle {{ $fmt($counts['nicht_lesbar']) }} Einträge mit Fehler aus der Datenbank entfernen (samt Vormerkungen)? Die Dateien bleiben unberührt – sind sie noch da und weiterhin kaputt, tauchen sie beim nächsten Einlesen wieder auf.">Alle aus der DB entfernen</button>
            @elseif ($view === 'jahr')
                <button class="btn-primary" wire:click="fixYears(null)" @disabled(! $total)
                        data-confirm-title="Jahr als Datum" data-confirm-ok="Nur das Jahr übernehmen" data-confirm="Bei allen {{ $fmt($total) }} Alben nur das Jahr übernehmen?">Alle: nur das Jahr</button>
            @elseif ($view === 'zeichen' && $aiAvailable)
                <button class="btn" wire:click="askAll" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('zeichen')" @disabled(! $unasked)
                        title="Alle Werte mit verlorenen Zeichen, die noch keine Vorschläge haben, im Hintergrund bei Claude anfragen">
                    Claude fragen <span class="text-xs text-zinc-500">({{ $fmt($unasked) }} offen)</span>
                </button>
            @endif
        </div>
    </header>

    <div class="max-w-5xl space-y-4 px-6 py-5">
        @if ($error)
            <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
        @elseif ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        @if ($view === 'replaygain')
            <p class="text-xs text-zinc-500">Gemessen wird nach EBU R128 (Bezug −18 LUFS) im Hintergrund – jede Datei wird einmal komplett gelesen. Der Album-Wert braucht alle Lieder des Albums; Ordner ohne gemeinsames Album: jedes Lied für sich. Nur in der Datenbank.</p>
            @forelse ($albums as $a)
                <section wire:key="rg-{{ $a['hash'] }}" class="flex items-center gap-4 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
                    <img src="{{ \App\Support\Covers::url($a['cover'], 100) }}" loading="lazy" class="size-12 shrink-0 rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $a['album'] ?: basename($a['dir']) }}@if ($a['loose'])<span class="ml-2 text-xs font-normal text-zinc-500">Einzeltitel</span>@endif</div>
                        <div class="truncate text-sm text-zinc-400">{{ $a['albumartist'] ?: 'Unbekannter Interpret' }}</div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$a['location']] }} · {{ $a['dir'] }}</div>
                    </div>
                    <div class="shrink-0 text-right text-xs text-zinc-400">
                        <div>{{ $a['missing'] }} von {{ $a['tracks'] }} {{ $a['tracks'] === 1 ? 'Lied' : 'Liedern' }} ohne ReplayGain</div>
                        @if ($a['measured'])<div class="text-zinc-500">{{ $a['measured'] }} schon gemessen</div>@endif
                    </div>
                    <button class="btn shrink-0" wire:click="measure(@js($a['hash']))" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('replaygain')">Messen</button>
                </section>
            @empty
                <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Alle Lieder haben ReplayGain.' }}</div>
            @endforelse
            @if ($total > count($albums))
                <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
            @endif
        @elseif ($view === 'duplikate')
            @php
                $roleText = ['keep' => ['✓', 'text-emerald-300', 'bleibt'], 'copies' => ['⬆', 'text-sky-300', 'bleibt – ersetzt die schlechtere Fassung im anderen Album'],
                    'moves' => ['⬆', 'text-sky-300', 'kommt ins Album und ersetzt die schlechtere Fassung'], 'trash' => ['✗', 'text-red-300', 'Papierkorb'], 'replaced' => ['✗', 'text-red-300', 'wird ersetzt → Papierkorb']];
                $rel = fn ($t) => ltrim(mb_substr($t->path, mb_strlen(\App\Support\Locations::root($t->location))), '/');
            @endphp
            <p class="text-xs text-zinc-500">Gleicher Interpret und Titel, Länge höchstens 2 s verschieden. Alben bleiben vollständig – dasselbe Lied auf Single und Best-of ist gewollt. Die bessere Datei gewinnt: Sie ersetzt eine schlechtere im Album (mit deren Tags, an deren Platz); lose Kopien und zweite Kopien im selben Album kommen in den Papierkorb. Ersetzen über Albumgrenzen: mit gleichem akustischem Fingerabdruck auch über „Alle vormerken“, sonst nur einzeln (Länge höchstens 1 s verschieden, kein Titel wie „Intro“); klar verschiedene Fingerabdrücke gelten als „kein Duplikat“. Nur vorgemerkt – ausgeführt wird beim Schreiben.</p>
            @forelse ($groups as $g)
                <section wire:key="dup-{{ $g['key'] }}" class="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
                    <div class="flex items-center gap-3 border-b border-zinc-800 px-4 py-2.5">
                        <img src="{{ \App\Support\Covers::url($g['cover'], 100) }}" loading="lazy" class="size-9 shrink-0 rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $g['title'] }}</div>
                            <div class="truncate text-sm text-zinc-400">{{ $g['artist'] }}</div>
                        </div>
                        @if ($g['planned'])
                            <span class="shrink-0 rounded bg-sky-500/15 px-1.5 py-0.5 text-[11px] font-medium text-sky-300">vorgemerkt</span>
                            <button class="btn shrink-0 py-0.5 text-xs" wire:click="revertDuplicates(@js($g['key']))">Zurücknehmen</button>
                        @else
                            <button class="btn shrink-0 py-0.5 text-xs" wire:click="exemptDuplicates(@js($g['key']), true)" title="Verschiedene Fassungen – nicht mehr anzeigen">Kein Duplikat</button>
                            <button class="btn-primary shrink-0 py-0.5 text-xs" wire:click="planDuplicates(@js($g['key']))">Vormerken</button>
                        @endif
                    </div>
                    <ul class="divide-y divide-zinc-800/70 text-sm">
                        @foreach ($g['members'] as $m)
                            @php [$icon, $color, $text] = $roleText[$m['role']]; $t = $m['track']; @endphp
                            <li class="flex items-center gap-3 px-4 py-1.5">
                                <span class="w-4 shrink-0 text-center {{ $color }}">{{ $icon }}</span>
                                <span class="w-28 shrink-0 text-xs {{ $t->error ? 'text-red-400' : 'text-zinc-400' }}" title="{{ $t->error }}">{{ $t->error ? 'defekt' : $t->qualityLabel() }} · {{ $t->durationLabel() }}</span>
                                <span class="min-w-0 flex-1 truncate text-zinc-300" title="{{ $t->path }}"><span class="text-zinc-500">{{ \App\Support\Locations::LABELS[$t->location] }} ·</span> {{ $rel($t) }}</span>
                                <span class="shrink-0 text-xs {{ $color }}">{{ $m['role'] === 'trash' && isset($m['reason']) ? 'Papierkorb ('.$m['reason'].')' : $text }}</span>
                                @if ($m['role'] === 'replaced')
                                    <span @class(['shrink-0 rounded px-1.5 py-0.5 text-[11px]', 'bg-emerald-500/15 text-emerald-300' => $m['verified'], 'bg-amber-500/15 text-amber-300' => ! $m['verified']])
                                          title="{{ $m['verified'] ? 'Der akustische Fingerabdruck bestätigt: dieselbe Aufnahme' : 'Ohne Fingerabdruck nur nach Titel und Länge – nur einzeln vormerkbar (nicht über „Alle vormerken“)' }}">{{ $m['verified'] ? 'akustisch geprüft' : 'nicht akustisch geprüft' }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Keine Duplikate.' }}</div>
            @endforelse
            @if ($total > $groups->count())
                <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
            @endif
            @if ($exempted->isNotEmpty())
                <details class="text-sm">
                    <summary class="cursor-pointer text-zinc-500 hover:text-zinc-300">{{ $exempted->count() }} als „kein Duplikat“ ausgenommen</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach ($exempted as $g)
                            <li wire:key="dup-x-{{ $g['key'] }}" class="flex items-center gap-3 rounded-md bg-zinc-900 px-3 py-1.5">
                                <span class="min-w-0 flex-1 truncate">{{ $g['artist'] }} – {{ $g['title'] }} <span class="text-xs text-zinc-500">({{ count($g['members']) }} Fassungen)</span></span>
                                <button class="btn shrink-0 py-0.5 text-xs" wire:click="exemptDuplicates(@js($g['key']), false)">Wieder aufnehmen</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @elseif (isset($check))
            <script type="application/json" id="genre-index">@json($genreIndex)</script>
            @include('livewire.partials.new-genre-dialog', [
                'submit' => '$wire.createGenre(ctx.key, ctx.trackId, name, parent)',
                'hint' => "'Wird im Genre-Baum angelegt und gleich ' + (ctx.trackId ? 'bei diesem Lied' : 'hier') + ' übernommen.'",
            ])
            @forelse ($units as $u)
                <section wire:key="genre-{{ $check }}-{{ $u['key'] }}" x-data="{ songs: @js($u['songs']) }" class="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
                    <div class="flex items-center gap-4 px-4 py-3">
                        <button class="group relative size-12 shrink-0" @click="$store.player.play(songs, 0)" title="{{ count($u['songs']) === 1 ? 'Anhören' : 'Alle '.count($u['songs']).' Lieder anhören' }}">
                            <img src="{{ \App\Support\Covers::url($u['cover'], 100) }}" loading="lazy" class="size-12 rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                            <span class="absolute inset-0 flex items-center justify-center rounded bg-black/50 text-lg text-white opacity-0 group-hover:opacity-100">▶</span>
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-medium">{{ $u['title'] ?: basename($u['dir']) }}</span>
                                <span class="shrink-0 rounded-full bg-zinc-700/60 px-2 py-0.5 text-[11px] font-medium text-zinc-300">{{ \App\Support\GenreChecks::KINDS[$u['kind']] }}</span>
                            </div>
                            <div class="truncate text-sm text-zinc-400">{{ $u['artist'] ?: 'Unbekannter Interpret' }}</div>
                            <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$u['location']] }} · {{ $u['dir'] }}@if ($u['kind'] === 'single' && $u['album']) · {{ $u['album'] }}@endif</div>
                        </div>
                        <div class="shrink-0 space-y-0.5 text-right text-sm">
                            @if ($u['kind'] !== 'sampler')
                                @foreach (array_slice($u['current'], 0, 3, true) as $value => $n)
                                    <div>@if ($value === '')<span class="text-zinc-500">ohne Genre</span>@else<span @class(['text-red-300' => $check === 'mismatch'])>{{ $value }}</span>@endif
                                        @if ($u['tracks'] > 1)<span class="text-xs text-zinc-500">· {{ $n }}</span>@endif</div>
                                @endforeach
                                @if (count($u['current']) > 3)<div class="text-xs text-zinc-500">+ {{ count($u['current']) - 3 }} weitere</div>@endif
                            @endif
                            <div class="text-xs text-zinc-500">{{ $u['kind'] === 'sampler' ? count($u['items']).' von '.$u['tracks'] : $u['tracks'] }} {{ ($u['kind'] === 'sampler' ? count($u['items']) : $u['tracks']) === 1 ? 'Lied' : 'Lieder' }}</div>
                        </div>
                        <div class="flex shrink-0 flex-col gap-1">
                            @if ($u['kind'] === 'sampler' && collect($u['items'])->contains(fn ($i) => $i['suggestions']))
                                <button class="btn py-0.5 text-xs" wire:click="takeGenreSuggestions(@js($u['key']))" title="Bei jedem Lied den ersten Vorschlag übernehmen">Alle Vorschläge</button>
                            @endif
                            @if (in_array($check, \App\Support\GenreChecks::EXEMPTIBLE, true))
                                <button class="btn py-0.5 text-xs" wire:click="exemptGenre(@js($u['key']), true)" title="Genre ist so gewollt – nicht mehr anzeigen">Passt so</button>
                            @endif
                        </div>
                    </div>

                    @if ($u['kind'] === 'sampler')
                        <ul class="divide-y divide-zinc-800 border-t border-zinc-800">
                            @foreach ($u['items'] as $item)
                                <li wire:key="genre-{{ $check }}-{{ $u['key'] }}-{{ $item['id'] }}" class="space-y-1.5 px-4 py-2.5">
                                    <div class="flex items-baseline gap-3 text-sm">
                                        <button class="shrink-0 text-zinc-500 hover:text-accent" @click="$store.player.play(songs, {{ $loop->index }})" :class="$store.player.isCurrent({{ $item['id'] }}) && 'text-accent'" title="Anhören">▶</button>
                                        <span class="min-w-0 flex-1 truncate"><span class="text-zinc-200" :class="$store.player.isCurrent({{ $item['id'] }}) && 'text-accent'">{{ $item['title'] }}</span> <span class="text-zinc-500">–</span> <span class="text-zinc-400">{{ $item['artist'] }}</span></span>
                                        @if ($item['genre'])<span @class(['shrink-0', 'text-red-300' => $check === 'mismatch'])>{{ $item['genre'] }}</span>@endif
                                    </div>
                                    @if ($item['profile'])<div class="text-xs text-zinc-500">Interpret: {{ $item['profile'] }}</div>@endif
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach ($item['suggestions'] as $i => $s)
                                            <button wire:click="takeGenre(@js($u['key']), {{ $item['id'] }}, @js($s['value']))" title="{{ $s['reason'] }}"
                                                    @class(['rounded-md border px-2 py-0.5 text-sm', 'border-accent/60 bg-accent/10 text-accent hover:bg-accent/20' => $i === 0,
                                                            'border-zinc-700 text-zinc-200 hover:border-zinc-500 hover:bg-zinc-800' => $i > 0])>{{ $s['value'] }}</button>
                                        @endforeach
                                        <form wire:submit="takeGenre(@js($u['key']), {{ $item['id'] }}, null)" class="flex min-w-60 flex-1 items-center gap-1.5"
                                              x-data="genrePicker(@js($u['key']), {{ $item['id'] }})" @keydown="keys($event)" @click.outside="open = false">
                                            <div class="relative flex-1">
                                                <input type="text" x-ref="input" wire:model="custom.g{{ $u['key'] }}-{{ $item['id'] }}" x-model="q" @focus="open = true" @input="open = true; active = 0; place()"
                                                       placeholder="Genre suchen …" autocomplete="off" class="field w-full py-0.5 text-sm">
                                                @include('livewire.partials.genre-results')
                                            </div>
                                            <button type="submit" class="btn shrink-0 py-0.5 text-xs">Übernehmen</button>
                                            <button type="button" class="shrink-0 text-xs font-medium text-accent hover:underline"
                                                    @click="$dispatch('new-genre', { name: q, ctx: { key: @js($u['key']), trackId: {{ $item['id'] }} } })">+ neues Genre …</button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="space-y-2 border-t border-zinc-800 px-4 py-3">
                            @if (count($u['songs']) > 1)
                                <details class="text-xs">
                                    <summary class="cursor-pointer text-zinc-500 hover:text-zinc-300">{{ count($u['songs']) }} Lieder zum Reinhören</summary>
                                    <ul class="mt-1.5 space-y-0.5">
                                        @foreach ($u['songs'] as $song)
                                            <li wire:key="genre-song-{{ $song['id'] }}" class="flex cursor-pointer items-baseline gap-3 rounded px-2 py-1 text-sm hover:bg-zinc-800"
                                                @click="$store.player.play(songs, {{ $loop->index }})" :class="$store.player.isCurrent({{ $song['id'] }}) && 'text-accent'">
                                                <span class="shrink-0 text-zinc-500">▶</span>
                                                <span class="min-w-0 flex-1 truncate">{{ $song['title'] }}@if ($song['artist'] !== $u['artist']) <span class="text-zinc-500">– {{ $song['artist'] }}</span>@endif</span>
                                                <span @class(['shrink-0 text-xs', 'text-zinc-500' => ! $song['affected'] || $check !== 'mismatch', 'text-red-300' => $song['affected'] && $check === 'mismatch'])>{{ $song['genre'] ?: 'ohne Genre' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            @if ($u['profile'])
                                <div class="flex items-baseline gap-3 text-xs"><span class="w-24 shrink-0 text-zinc-500">Interpret</span><span class="text-zinc-300">{{ $u['profile'] }}</span></div>
                            @endif
                            @if ($u['suggestions'])
                                <div class="flex items-start gap-3">
                                    <span class="w-24 shrink-0 pt-1 text-xs text-zinc-500">Vorschlag</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($u['suggestions'] as $i => $s)
                                            <button wire:click="takeGenre(@js($u['key']), null, @js($s['value']))" title="{{ $s['reason'] }}"
                                                    @class(['rounded-md border px-2 py-1 text-left text-sm', 'border-accent/60 bg-accent/10 text-accent hover:bg-accent/20' => $i === 0,
                                                            'border-zinc-700 text-zinc-200 hover:border-zinc-500 hover:bg-zinc-800' => $i > 0])>
                                                <span class="block">{{ $s['value'] }}</span>
                                                <span class="block text-[11px] text-zinc-500">{{ $s['reason'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <form wire:submit="takeGenre(@js($u['key']), null, null)" class="flex items-center gap-3"
                                  x-data="genrePicker(@js($u['key']), null)" @keydown="keys($event)" @click.outside="open = false">
                                <span class="w-24 shrink-0 text-xs text-zinc-500">Selbst</span>
                                <div class="relative flex-1">
                                    <input type="text" x-ref="input" wire:model="custom.g{{ $u['key'] }}" x-model="q" @focus="open = true" @input="open = true; active = 0; place()"
                                           placeholder="Genre suchen – Name, Teil davon, Oberbegriff oder Synonym …" autocomplete="off" class="field w-full">
                                    @include('livewire.partials.genre-results')
                                </div>
                                <button type="submit" class="btn shrink-0">Übernehmen</button>
                                <button type="button" class="shrink-0 text-xs font-medium text-accent hover:underline"
                                        @click="$dispatch('new-genre', { name: q, ctx: { key: @js($u['key']), trackId: null } })">+ neues Genre …</button>
                            </form>
                        </div>
                    @endif
                </section>
            @empty
                <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : ['missing' => 'Alle Lieder haben ein Genre.', 'mismatch' => 'Alle Genres passen zu ihren Interpreten.', 'mixed' => 'Alle Alben haben ein einheitliches Genre.'][$check] }}</div>
            @endforelse
            @if ($units->isNotEmpty())
                <p class="text-xs text-zinc-500">Alben bekommen ein gemeinsames Genre, Sampler und Einzeltitel je Lied das ihres Interpreten. Mehrere Genres mit „; “ trennen – höchstens zwei: das speziellste, dann sein Hauptgenre.
                    Nur in der Datenbank, unter Änderungen zurücksetzbar.</p>
            @endif
            @if ($total > $units->count())
                <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
            @endif
            @if ($exempted->isNotEmpty())
                <details class="text-sm">
                    <summary class="cursor-pointer text-zinc-500 hover:text-zinc-300">{{ $exempted->count() }} mit „Passt so“ ausgenommen</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach ($exempted as $x)
                            <li wire:key="genre-x-{{ $x['key'] }}" class="flex items-center gap-3 rounded-md bg-zinc-900 px-3 py-1.5">
                                <span class="min-w-0 flex-1 truncate">{{ $x['artist'] }} – {{ $x['title'] ?: basename($x['dir']) }} <span class="text-xs text-zinc-500">({{ \App\Support\GenreChecks::KINDS[$x['kind']] }})</span></span>
                                <button class="btn shrink-0 py-0.5 text-xs" wire:click="exemptGenre(@js($x['key']), false)">Wieder aufnehmen</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @elseif ($view === 'fingerabdruck')
            <p class="text-xs text-zinc-500">Der akustische Fingerabdruck (Chromaprint – wie bei AcoustID und Picard) zeigt, ob zwei Dateien wirklich dieselbe Aufnahme sind. Duplikate ersetzen über Albumgrenzen nur damit auch über „Alle vormerken“. Ermittelt wird lokal, ohne Dienst im Internet; jede Datei wird dafür einmal gelesen (die ersten 2 Minuten). In Inbox und Lib kommt er beim Schreiben ins Tag ACOUSTID_FINGERPRINT, im Blackhole bleibt er nur in der Datenbank. Neue Lieder der Inbox bekommen ihn nach dem Einlesen automatisch.</p>
            @foreach ($prints as $location => $p)
                <section wire:key="fp-{{ $location }}" class="flex items-center gap-4 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ \App\Support\Locations::LABELS[$location] }}</div>
                        <div class="text-sm text-zinc-400">{{ $fmt($p['done']) }} von {{ $fmt($p['total']) }} {{ $p['total'] === 1 ? 'Lied' : 'Liedern' }} mit Fingerabdruck
                            @if ($p['pending']) · <span class="text-amber-300">{{ $fmt($p['pending']) }} offen</span>@endif
                            @if ($p['failed']) · <span class="text-zinc-500" title="fpcalc konnte die Datei nicht lesen – erneut, sobald sie sich ändert">{{ $fmt($p['failed']) }} nicht möglich</span>@endif
                        </div>
                    </div>
                    <button class="btn shrink-0" wire:click="measureFingerprints(@js($location))" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('fingerprint')" @disabled(! $p['pending'])
                            data-confirm-title="Fingerabdrücke ermitteln" data-confirm-ok="Ermitteln" data-confirm="{{ $fmt($p['pending']) }} {{ $p['pending'] === 1 ? 'Lied' : 'Lieder' }} in {{ \App\Support\Locations::LABELS[$location] }} im Hintergrund ermitteln? Jede Datei wird einmal gelesen – das dauert.">Ermitteln</button>
                </section>
            @endforeach
        @elseif ($view === 'kaputt')
            @forelse ($items as $i)
                @php $t = $i['track']; @endphp
                <section wire:key="kaputt-{{ $t->id }}" @class(['flex items-center gap-4 rounded-lg border px-4 py-3', 'border-zinc-800 bg-zinc-900' => ! $i['trash'], 'border-red-500/30 bg-red-500/5' => $i['trash']])>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $t->title ?: basename($t->path) }} @if ($t->artist)<span class="font-normal text-zinc-400">– {{ $t->artist }}</span>@endif</div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500" title="{{ $t->path }}">{{ \App\Support\Locations::LABELS[$t->location] ?? $t->location }} · {{ $t->album ?: dirname($t->path) }} · {{ basename($t->path) }}</div>
                        <div class="mt-1 text-xs">
                            <span class="text-red-300">kein Audio – {{ $t->durationLabel() }}, {{ $fmt((int) round($t->size / 1024)) }} KB</span>
                            @if ($i['intact'])
                                <span class="text-emerald-300/90"> · intakte Fassung im Album: {{ $i['intact']->track ? 'Nr. '.$i['intact']->track.' – ' : '' }}{{ basename($i['intact']->path) }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($i['trash'])
                        <span class="shrink-0 text-xs text-red-300">→ Papierkorb vorgemerkt</span>
                        <button class="btn shrink-0 py-0.5 text-xs" wire:click="keepBroken({{ $t->id }})">Zurücknehmen</button>
                    @else
                        <button class="btn shrink-0" wire:click="trashBroken({{ $t->id }})">In den Papierkorb</button>
                    @endif
                </section>
            @empty
                <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Keine kaputten Lieder.' }}</div>
            @endforelse
            @if ($items->isNotEmpty())
                <p class="text-xs text-zinc-500">Vorgemerkt wird nur – in den Papierkorb wandern die Dateien beim Schreiben (Seite „Schreiben“).</p>
            @endif
            @if ($total > $items->count())
                <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
            @endif
        @elseif ($view === 'nicht_lesbar')
            @forelse ($broken as $t)
                <section wire:key="broken-{{ $t->id }}" class="rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
                    <div class="truncate font-medium" title="{{ $t->path }}">{{ basename($t->path) }}</div>
                    <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$t->location] ?? $t->location }} · {{ dirname($t->path) }}</div>
                    <div class="mt-1 text-sm text-red-300">{{ $t->error }}</div>
                </section>
            @empty
                <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Alle Dateien sind lesbar.' }}</div>
            @endforelse
            @if ($total > $broken->count())
                <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
            @endif
        @elseif ($view === 'jahr')
            @forelse ($albums as $a)
                <section wire:key="year-{{ $a['hash'] }}" class="flex items-center gap-4 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
                    <img src="{{ \App\Support\Covers::url($a['cover'], 100) }}" loading="lazy" class="size-12 shrink-0 rounded bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'" alt="">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $a['album'] ?: basename($a['dir']) }}</div>
                        <div class="truncate text-sm text-zinc-400">{{ $a['albumartist'] ?: 'Unbekannter Interpret' }}</div>
                        <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$a['location']] }} · {{ $a['dir'] }}</div>
                    </div>
                    <div class="shrink-0 space-y-0.5 text-right text-sm">
                        @foreach (array_slice($a['values'], 0, 3, true) as $raw => $year)
                            <div><span class="text-zinc-500 line-through">{{ $raw }}</span> → <span class="font-medium">{{ $year }}</span></div>
                        @endforeach
                        @if (count($a['values']) > 3)<div class="text-xs text-zinc-500">+ {{ count($a['values']) - 3 }} weitere</div>@endif
                        <div class="text-xs text-zinc-500">{{ $a['tracks'] }} {{ $a['tracks'] === 1 ? 'Lied' : 'Lieder' }}</div>
                    </div>
                    <button class="btn shrink-0" wire:click="fixYears(@js($a['hash']))">Nur das Jahr</button>
                </section>
            @empty
                <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Überall steht nur die Jahreszahl.' }}</div>
            @endforelse
            @if ($albums->isNotEmpty())
                <p class="text-xs text-zinc-500">Nur in der Datenbank, unter Änderungen zurücksetzbar. Steht im Jahr keine Jahreszahl, wird nichts angefasst.</p>
            @endif
            @if ($total > $albums->count())
                <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
            @endif
        @else
        @if (! $aiAvailable)
            <div class="rounded-md bg-sky-500/10 px-3 py-2 text-sm text-sky-300">
                Für Vorschläge bei verlorenen Zeichen den Anthropic-API-Key unter <a href="{{ route('settings', ['tab' => 'zugangsdaten']) }}" wire:navigate class="underline">Einstellungen › Zugangsdaten</a> eintragen. Sicher Reparierbares und eigene Schreibweisen gehen auch ohne.
            </div>
        @endif

        @forelse ($problems as $p)
            @php $repair = $stored[$p['hash']] ?? null; $review = ! array_diff($p['kinds'], \App\Support\TextRepairs::REVIEW); @endphp
            <section wire:key="repair-{{ $p['hash'] }}" class="overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900">
                <div class="flex flex-wrap items-center gap-3 border-b border-zinc-800 px-4 py-2.5">
                    <span class="min-w-0 flex-1 break-words font-medium">{{ \App\Support\TextRepairs::highlight($p['value']) }}</span>
                    @foreach ($p['kinds'] as $kind)
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $kindStyle[$kind] }}">{{ \App\Support\TextRepairs::KINDS[$kind] }}</span>
                    @endforeach
                    @if ($review)
                        <button class="btn shrink-0 py-0.5 text-xs" wire:click="accept(@js($p['hash']))" title="Schreibweise ist richtig – nicht mehr anzeigen">Passt so</button>
                    @endif
                </div>
                <div class="space-y-3 px-4 py-3">
                    <div class="flex items-baseline gap-3 text-xs">
                        <span class="w-24 shrink-0 text-zinc-500">Wo</span>
                        <span class="text-zinc-300">{{ collect($p['fields'])->map(fn ($n, $f) => $fieldLabels[$f].' · '.$fmt($n).' '.($n === 1 ? 'Lied' : 'Lieder'))->implode(' — ') }}</span>
                    </div>

                    {{-- sichere Reparatur --}}
                    @if ($p['rule'] !== null)
                        <div class="flex items-center gap-3">
                            <span class="w-24 shrink-0 text-xs text-zinc-500">Vorschlag</span>
                            <button class="btn border-accent/60 bg-accent/10 py-0.5 text-accent hover:bg-accent/20" wire:click="take(@js($p['hash']), @js($p['rule']))">„{{ $p['rule'] }}“ übernehmen</button>
                            <span class="text-xs text-zinc-400">{{ $p['safe'] ? 'sicher zurückgerechnet – ohne Raten' : 'bitte prüfen' }}</span>
                        </div>
                    @endif

                    {{-- Claude (nur bei Encoding-Fehlern) --}}
                    @unless ($review)
                    <div class="flex items-start gap-3">
                        <span class="w-24 shrink-0 pt-1 text-xs text-zinc-500">Claude</span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            @if ($repair?->suggestions)
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($repair->suggestions as $i => $s)
                                        <button wire:click="take(@js($p['hash']), @js($s['text']))" title="{{ $s['reason'] }}"
                                                @class(['rounded-md border px-2 py-1 text-left text-sm',
                                                        'border-accent/60 bg-accent/10 text-accent hover:bg-accent/20' => $i === 0,
                                                        'border-zinc-700 text-zinc-200 hover:border-zinc-500 hover:bg-zinc-800' => $i > 0])>
                                            <span class="block">{{ $s['text'] }}</span>
                                            <span class="block text-[11px] text-zinc-500">{{ $s['reason'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif ($repair && $repair->suggestions !== null)
                                <p class="text-xs text-zinc-400">Claude hat keinen Vorschlag gefunden.</p>
                            @endif
                            @if ($repair?->error)
                                <p class="text-xs text-red-300">{{ $repair->error }}</p>
                            @endif
                            <div class="flex items-center gap-3 text-xs text-zinc-500">
                                @if ($aiAvailable)
                                    <button class="btn py-0.5 text-xs" wire:click="ask(@js($p['hash']))" wire:loading.attr="disabled" wire:target="ask(@js($p['hash']))">
                                        {{ $repair?->asked_at ? 'Neu fragen' : 'Vorschläge holen' }}
                                    </button>
                                    <span wire:loading wire:target="ask(@js($p['hash']))">Claude denkt nach …</span>
                                @endif
                                @if ($repair?->asked_at)
                                    <span>{{ $repair->model }} · {{ $repair->asked_at->format('d.m.Y H:i') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @endunless

                    {{-- eigene Schreibweise --}}
                    <form wire:submit="take(@js($p['hash']), null)" class="flex items-center gap-3">
                        <span class="w-24 shrink-0 text-xs text-zinc-500">Selbst</span>
                        <input type="text" wire:model="custom.{{ $p['hash'] }}" placeholder="{{ $p['rule'] ?? str_replace("\u{FFFD}", '…', $p['value']) }}" class="field flex-1">
                        <button type="submit" class="btn shrink-0">Übernehmen</button>
                    </form>
                </div>
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Keine kaputten Zeichen, nichts zu prüfen.' }}</div>
        @endforelse
        @if ($problems->isNotEmpty())
            <p class="text-xs text-zinc-500">Übernommen wird in allen Liedern und Feldern mit genau diesem Wert – nur in der Datenbank, unter Änderungen zurücksetzbar. Ordner- und Dateinamen bleiben unverändert.</p>
        @endif
        @if ($total > $problems->count())
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
        @endif
    </div>
    </div>
</div>
