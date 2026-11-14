{{-- Schreiben: je Album Tags und Ziel (Sortieren), Start alle bzw. je Album --}}
@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $kinds = ['move' => 'verschieben', 'rename' => 'umbenennen', 'trash' => 'in den Papierkorb', 'inbox' => 'zurück in die Inbox', 'copy-in' => 'bessere Fassung kopieren', 'move-in' => 'bessere Fassung holen'];
@endphp
<div class="min-h-full">
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Schreiben</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Album' : 'Alben' }} · {{ $fmt($tracks) }} {{ $tracks === 1 ? 'Lied' : 'Lieder' }} mit offenen Änderungen{{ $tracks > $writable ? ', '.$fmt($tracks - $writable).' nicht schreibbar' : '' }} · {{ $fmt($files) }} {{ $files === 1 ? 'Datei' : 'Dateien' }} zu verschieben</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="query" placeholder="Album, Interpret oder Ordner suchen …" class="field pl-8">
            </div>
            <button class="btn-primary" wire:click="write(null)" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('write')" @disabled(! $startable)
                    data-confirm-title="Schreiben" data-confirm-ok="Schreiben" data-confirm="{{ $fmt($startable) }} {{ $startable === 1 ? 'Album' : 'Alben' }} schreiben: Tags in die Dateien, dann {{ $fmt($files) }} {{ $files === 1 ? 'Datei' : 'Dateien' }} an ihren Regelort bzw. in den Papierkorb?">Alle schreiben</button>
        </div>
    </header>

    <div class="max-w-5xl space-y-4 px-6 py-5">
        @if ($error)
            <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
        @elseif ($message)
            <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        <p class="text-xs text-zinc-500">Je Album: erst die Tags (nur MP3 und FLAC; passt es in den Reserveplatz, direkt in die Datei, sonst über eine geprüfte Kopie – danach wird nachgelesen), dann wandern die Lieder mit Kategorie an ihren Regelort (Einstellungen › Dateinamen), Vorgemerktes in den Papierkorb bzw. zurück in die Inbox. Ein Album wandert nur als Ganzes in einen Ordner, nichts wird überschrieben; Bilder ziehen mit, leere Ordner verschwinden. Verschoben wird erst, wenn die Tags des Albums geschrieben sind.</p>
        @if ($failed)
            <details class="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-300" open>
                <summary class="cursor-pointer">{{ count($failed) }} {{ count($failed) === 1 ? 'Datei' : 'Dateien' }} beim letzten Schreiben nicht (ganz) geschrieben</summary>
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach (array_slice($failed, 0, 50, true) as $path => $reason)
                        <li><span class="text-red-200">{{ $path }}</span> – {{ $reason }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
        @forelse ($albums as $a)
            @php $plan = $plans[$a['hash']] ?? null; @endphp
            <section wire:key="write-{{ $a['hash'] }}" class="rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3">
            <div class="flex items-center gap-4">
                <img src="{{ \App\Support\Covers::url($a['cover'], 100) }}" loading="lazy" class="size-12 shrink-0 rounded bg-zinc-800 object-cover" onerror="noCover(this)" alt="">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ $a['album'] ?: basename($a['dir']) }}</div>
                    <div class="truncate text-sm text-zinc-400">{{ $a['albumartist'] ?: 'Unbekannter Interpret' }}</div>
                    <div class="mt-0.5 truncate text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$a['location']] }} · {{ $a['dir'] }}</div>
                </div>
                <div class="w-96 shrink-0 space-y-0.5 text-right text-xs text-zinc-400">
                    @if ($a['tracks'])
                        <div class="truncate" title="{{ implode(', ', $a['fields']) }}">Tags: {{ $a['tracks'] }} {{ $a['tracks'] === 1 ? 'Lied' : 'Lieder' }} – {{ implode(', ', $a['fields']) }}@if ($a['unwritable'])<span class="text-amber-300"> · {{ $a['unwritable'] }} nicht schreibbar</span>@endif</div>
                    @endif
                    @if ($plan && $plan['blocked'] && ! $plan['counts'])
                        <div class="text-amber-300">bleibt liegen: {{ $plan['blocked'] }}</div>
                        @if ($plan['split'] ?? null)
                            {{-- welche Lieder wohin wollen – unterschiedliche Tags hervorgehoben --}}
                            <details class="mt-1 text-left" wire:key="split-{{ $a['hash'] }}">
                                <summary class="cursor-pointer text-right text-zinc-400 hover:text-zinc-200">Warum? {{ count($plan['split']['groups']) }} Ordner{{ $plan['split']['differs'] ? ' – unterschiedlich: '.collect($plan['split']['differs'])->map(fn ($f) => \App\Support\Sorter::FOLDER_FIELDS[$f])->implode(', ') : '' }}</summary>
                                <div class="mt-1.5 space-y-1.5">
                                    @foreach ($plan['split']['groups'] as $g)
                                        <div class="rounded border border-zinc-800 bg-zinc-950/60 px-2 py-1.5">
                                            <div class="truncate font-medium text-zinc-300" title="{{ $g['label'] }}">{{ $g['label'] }} <span class="font-normal text-zinc-500">({{ count($g['tracks']) }} {{ count($g['tracks']) === 1 ? 'Lied' : 'Lieder' }})</span></div>
                                            <div class="mt-0.5 flex flex-wrap gap-x-3">
                                                @foreach ($g['values'] as $f => $v)
                                                    @if (in_array($f, $plan['split']['differs'], true))<span><span class="text-zinc-500">{{ \App\Support\Sorter::FOLDER_FIELDS[$f] }}:</span> <span class="text-amber-300">{{ $v }}</span></span>@endif
                                                @endforeach
                                            </div>
                                            <div class="mt-0.5 truncate text-zinc-500" title="{{ implode(' · ', $g['tracks']) }}">{{ implode(' · ', array_slice($g['tracks'], 0, 6)) }}{{ count($g['tracks']) > 6 ? ' …' : '' }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    @elseif ($plan)
                        @if ($plan['target'])
                            <div class="truncate text-sky-300" title="{{ $plan['target'] }}">→ {{ \App\Support\Locations::LABELS[$plan['target_location']] ?? '' }} · {{ $plan['target'] }}</div>
                        @endif
                        <div class="text-zinc-500">{{ collect($plan['counts'])->map(fn ($n, $k) => $n.' '.$kinds[$k])->implode(' · ') }}</div>
                        @foreach ($plan['blocked_items'] as $b)<div class="truncate text-amber-300" title="{{ $b }}">bleibt: {{ $b }}</div>@endforeach
                    @endif
                </div>
                <button class="btn shrink-0" wire:click="write(@js($a['hash']))" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('write')"
                        @disabled(($a['unwritable'] === $a['tracks']) && ! ($plan['counts'] ?? []))>Schreiben</button>
            </div>
            @if ($a['changes'])
                {{-- je Lied: welches Feld von welchem Wert zu welchem (Geändertes markiert) --}}
                <details class="mt-2 border-t border-zinc-800 pt-2" wire:key="tags-{{ $a['hash'] }}">
                    <summary class="cursor-pointer text-xs text-zinc-400 hover:text-zinc-200">Tags: {{ $a['tracks'] }} {{ $a['tracks'] === 1 ? 'Lied' : 'Lieder' }} – bisher → neu</summary>
                    <div class="mt-2 space-y-1.5 text-[11px] leading-snug">
                        @foreach ($a['changes'] as $c)
                            <div class="rounded bg-zinc-950/60 px-2 py-1.5">
                                <div class="mb-0.5 truncate font-medium text-zinc-300">@if ($c['track']){{ $c['disc'] > 1 ? $c['disc'].'-' : '' }}{{ $c['track'] }}. @endif{{ $c['title'] }}</div>
                                <div class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-0.5">
                                    @foreach ($c['fields'] as $f)
                                        <div class="text-zinc-500">{{ $f['label'] }}</div>
                                        <div class="min-w-0 break-all font-mono">
                                            @if ($f['old'] === null)
                                                <span class="font-sans text-emerald-300">{{ $f['new'] }}</span>
                                            @else
                                                @if ($f['old'] === '')<span class="font-sans italic text-zinc-600">leer</span>@else{!! \App\Support\TextDiff::html($f['old'], $f['diff'], \App\Support\TextDiff::REMOVED) !!}@endif
                                                <span class="font-sans text-zinc-600"> → </span>
                                                @if ($f['new'] === '')<span class="font-sans italic text-red-300">leer</span>@else{!! \App\Support\TextDiff::html($f['new'], $f['diff'], \App\Support\TextDiff::ADDED) !!}@endif
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
            @if ($plan['files'] ?? [])
                {{-- je Datei: von → nach (Bereich, Ordner, Dateiname; Geändertes hell) --}}
                <details class="mt-2 border-t border-zinc-800 pt-2" wire:key="files-{{ $a['hash'] }}">
                    <summary class="cursor-pointer text-xs text-zinc-400 hover:text-zinc-200">{{ count($plan['files']) }} {{ count($plan['files']) === 1 ? 'Datei' : 'Dateien' }}: von → nach</summary>
                    <div class="mt-2 space-y-1.5 font-mono text-[11px] leading-snug">
                        @foreach ($plan['files'] as $f)
                            @php
                                // Pfad mit markiertem Unterschied: gleicher Anfang/Ende grau, Geändertes rot (fällt weg) bzw. grün (kommt dazu)
                                $show = fn (array $w, ?array $diff, string $mark) => '<span class="text-zinc-500">'.e(\App\Support\Locations::LABELS[$w['location']] ?? '?').' · </span>'
                                    .\App\Support\TextDiff::html($w['path'], $diff, $mark);
                            @endphp
                            <div class="grid grid-cols-[7rem_1fr] gap-x-3 rounded bg-zinc-950/60 px-2 py-1.5">
                                <div @class(['font-sans text-[11px]', 'text-sky-300' => in_array($f['kind'], ['move', 'rename'], true), 'text-red-300' => $f['kind'] === 'trash', 'text-emerald-300' => in_array($f['kind'], ['inbox', 'copy-in', 'move-in'], true)])
                                     title="{{ $f['reason'] }}">{{ $kinds[$f['kind']] ?? $f['kind'] }}</div>
                                <div class="min-w-0 break-all">
                                    <div><span class="font-sans text-zinc-600">von </span>{!! $show($f['from'], $f['diff'], \App\Support\TextDiff::REMOVED) !!}</div>
                                    <div><span class="font-sans text-zinc-600">nach </span>@if ($f['to']){!! $show($f['to'], $f['diff'], \App\Support\TextDiff::ADDED) !!}@else<span class="font-sans text-amber-300">Ziel steht noch nicht fest</span>@endif</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
            </section>
        @empty
            <div class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Alles geschrieben und einsortiert.' }}</div>
        @endforelse
        @if ($total > count($albums))
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    </div>
</div>
