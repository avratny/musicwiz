@php $rel = fn ($p) => str_starts_with((string) $p, $base.'/') ? substr($p, strlen($base) + 1) : $p; @endphp
<div class="flex h-full flex-col overflow-hidden" @if ($active->isNotEmpty() || $watching || ($integrity['running'] ?? false)) wire:poll.2s @endif>
    <x-page-header title="Aufräumen" subtitle="Prüfungen auf den Scan-Daten der Lib (nicht Inbox und Blackhole). Behoben wird erst nach Klick – mit Vorschau, rückgängig machbar." />
    @foreach ($autoHeld as $heldCheck => $h)
        <div class="mx-6 mt-3 shrink-0 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
            Automatik „{{ $h['label'] }}“ angehalten: {{ $h['count'] }} Schritte auf einmal (mehr als {{ \App\Support\AutoCleanup::LIMIT }} laufen nie ohne Nachfrage).
            <button wire:click="$set('check', @js($heldCheck))" class="underline">Hier mit Vorschau prüfen</button>
        </div>
    @endforeach
    <div class="flex min-h-0 flex-1 overflow-hidden">
        <nav class="w-80 shrink-0 overflow-y-auto border-r border-zinc-800 p-3">
            @foreach (\App\Support\CleanupChecks::STEPS as $n => [$stepLabel, $stepHint, $stepChecks])
                @php $stepOpen = array_sum(array_map(fn ($k) => $counts[$k] ?? 0, $stepChecks)); @endphp
                <div class="mt-3 mb-1 flex items-center gap-2 px-2 first:mt-0" title="{{ $stepHint }}">
                    <span class="grid size-5 place-items-center rounded-full text-[11px] font-semibold {{ $stepOpen ? 'bg-violet-600 text-white' : 'bg-emerald-600/80 text-white' }}">{{ $stepOpen ? $n : '✓' }}</span>
                    <span class="text-xs font-semibold tracking-wide text-zinc-300 uppercase">{{ $stepLabel }}</span>
                </div>
                <div class="space-y-0.5">
            @foreach ($stepChecks as $key)
                @php [$label] = $defs[$key]; @endphp
                <button wire:click="$set('check', '{{ $key }}')"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition {{ $check === $key ? 'bg-zinc-800 text-white ring-1 ring-zinc-700' : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-100' }}">
                    <span class="flex-1">{{ $label }}</span>
                    @if (! empty($auto[$key]))<span class="badge-blue" title="Automatik aktiv für: {{ implode(', ', array_map(fn ($c) => $c === '_other' ? 'Sonstige' : $c, array_keys($auto[$key]))) }}">auto</span>@endif
                    <span class="{{ ($counts[$key] ?? 0) ? 'badge-violet' : 'badge-gray' }}">{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
                </div>
            @endforeach
        </nav>

        <section class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <div class="flex shrink-0 items-center gap-3 border-b border-zinc-800 px-6 py-3">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $defs[$check][0] }}</div>
                    <div class="text-xs text-zinc-500">{{ $defs[$check][1] }} {{ $total }} Fund(e).</div>
                </div>
                @if ($check === 'integrity')
                    <div class="text-right text-xs text-zinc-500">
                        @if ($integrity['running'] ?? false)
                            Prüfe {{ $integrity['done'] ?? 0 }}{{ isset($integrity['total']) ? ' von '.$integrity['total'] : '' }} …{{ ! empty($integrity['current']) ? ' ('.$integrity['current'].')' : '' }}
                        @elseif (! empty($integrity['error']))
                            <span class="text-red-300">Abgebrochen: {{ $integrity['error'] }}</span>
                        @elseif (! empty($integrity['finished_at']))
                            Zuletzt geprüft {{ \Illuminate\Support\Carbon::parse($integrity['finished_at'])->diffForHumans() }} ({{ $integrity['done'] ?? 0 }} Dateien)
                        @else
                            Noch nie geprüft.
                        @endif
                    </div>
                    <button wire:click="startIntegrity" class="btn-ghost btn-sm" @disabled($integrity['running'] ?? false) title="Jede Lib-Datei einmal komplett dekodieren – beim ersten Mal dauert das Stunden, danach nur noch Neues und Geändertes">Audiodaten prüfen</button>
                @endif
                @if ($check === 'encoding')
                    <a href="{{ route('text-repair') }}" wire:navigate class="btn-ghost btn-sm" title="Codepage, verlorene Zeichen und alles andere einzeln entscheiden – auch in der Inbox">Zeichen-Assistent …</a>
                @endif
                @if ($fixable && $total)
                    <button wire:click="selectAll" class="btn-ghost btn-sm">Alle markieren</button>
                    <button wire:click="previewFix(false)" class="btn-ghost" @disabled(! $selected || $active->isNotEmpty())>Markierte beheben ({{ count($selected) }}) …</button>
                    <button wire:click="previewFix(true)" class="btn-primary" @disabled($active->isNotEmpty()) title="{{ $active->isNotEmpty() ? 'Es läuft noch eine Aktion' : '' }}">Alle beheben …</button>
                @endif
            </div>
            @if ($openBefore && $step === 4 && $total)
                <div class="shrink-0 px-6 pt-3">
                    <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
                        <strong>Erst die Schritte davor erledigen.</strong> Der Ordner richtet sich nach den Tags – sonst wird später ein zweites Mal verschoben.
                        <div class="mt-1 flex flex-wrap gap-2">
                            @foreach ($openBefore as $k => $n)
                                <button wire:click="$set('check', '{{ $k }}')" class="badge-amber hover:bg-amber-500/30">{{ \App\Support\CleanupChecks::stepOf($k) }}. {{ $defs[$k][0] }} · {{ $n }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
            @foreach ($active as $op)
                <div class="shrink-0 px-6 pt-3" wire:key="run{{ $op->id }}">
                    <div class="rounded-lg border border-violet-500/30 bg-violet-500/10 px-4 py-2 text-sm text-violet-200">
                        <div class="flex items-center gap-2">
                            <span class="size-2 animate-pulse rounded-full bg-violet-400"></span>
                            <span class="min-w-0 flex-1 truncate" title="{{ $op->description }}">{{ $op->description }}</span>
                            <span class="tabular-nums">{{ $op->done + $op->failed }} / {{ $op->total }}</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                            <div class="h-full bg-gradient-to-r from-blue-500 to-violet-500 transition-all" style="width: {{ min(100, round(($op->done + $op->failed) / max(1, $op->total) * 100)) }}%"></div>
                        </div>
                        <div class="mt-1 text-xs text-violet-300/70">Liste und Zahlen werden aktualisiert, sobald die Aktion fertig ist.</div>
                    </div>
                </div>
            @endforeach
            @if ($done)
                <div class="shrink-0 px-6 pt-3">
                    <div class="flex items-start gap-3 rounded-lg border px-4 py-2 text-sm whitespace-pre-line {{ str_contains($done, '⚠') ? 'border-amber-500/30 bg-amber-500/10 text-amber-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' }}">
                        <span class="flex-1">{{ $done }} – Liste aktualisiert.</span>
                        <a href="{{ route('history') }}" wire:navigate class="shrink-0 underline">Verlauf</a>
                        <button wire:click="$set('done', null)" class="shrink-0 opacity-70 hover:opacity-100">✕</button>
                    </div>
                </div>
            @endif
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-3">
                <div class="space-y-1.5">
                    @forelse ($findings as $f)
                        <div wire:key="f{{ $f['id'] }}" class="flex items-start gap-3 rounded-lg border border-zinc-800 bg-zinc-900/50 px-3 py-2">
                            @if ($fixable)
                                <input type="checkbox" wire:model.live="selected" value="{{ $f['id'] }}" class="mt-1 rounded border-zinc-600 bg-zinc-800" @disabled(! $f['actions'])>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium" title="{{ $f['title'] }}">{{ $f['title'] }}</div>
                                @if ($check === 'duplicates')
                                    <div class="mt-1 space-y-0.5 text-xs">
                                        @foreach ($f['options'] as $i => $o)
                                            @if ($f['choosable'] ?? false)
                                                <label class="flex items-center gap-2 font-mono text-zinc-400">
                                                    <input type="radio" name="keep-{{ $f['id'] }}" wire:model.live="keep.{{ $f['id'] }}" value="{{ $o['path'] }}" class="border-zinc-600 bg-zinc-800">
                                                    <span class="truncate" title="{{ $o['label'] }}">{{ $o['label'] }}</span>
                                                    <span class="shrink-0 text-zinc-500">{{ $o['info'] ?? '' }}</span>
                                                    <x-play-button :id="$o['id']" :seconds="$o['seconds'] ?? null" :title="$o['title']" :artist="$o['artist']" :album="$o['album']" size="size-6" />
                                                </label>
                                            @else
                                                <div class="flex items-center gap-2 font-mono">
                                                    <span class="w-4 shrink-0 text-center {{ ['✓' => 'text-emerald-400', '⬆' => 'text-blue-400', '✗' => 'text-red-400'][$o['icon']] ?? 'text-zinc-400' }}">{{ $o['icon'] }}</span>
                                                    <span class="truncate text-zinc-300" title="{{ $o['label'] }}">{{ $o['label'] }}</span>
                                                    <span class="shrink-0 text-zinc-500">{{ $o['info'] ?? '' }}</span>
                                                    <x-play-button :id="$o['id']" :seconds="$o['seconds'] ?? null" :title="$o['title']" :artist="$o['artist']" :album="$o['album']" size="size-6" />
                                                </div>
                                                <div class="ml-6 font-sans text-[11px] {{ ['✓' => 'text-emerald-300/70', '⬆' => 'text-blue-300/80', '✗' => 'text-red-300/70'][$o['icon']] ?? 'text-zinc-500' }}">{{ $o['role'] }}</div>
                                            @endif
                                        @endforeach
                                        @if ($f['choosable'] ?? false)
                                            <div class="font-sans text-zinc-500">Alle in „Kein Album“: gewählte Datei bleibt, die anderen wandern in den Papierkorb.</div>
                                        @else
                                            <div class="font-sans text-zinc-500">Album-Schutz: Jedes Album behält seinen Track.</div>
                                        @endif
                                        <button wire:click="markDifferent(@js($f['id']))" class="mt-1 rounded border border-zinc-700 px-2 py-0.5 font-sans text-zinc-300 hover:border-zinc-500 hover:text-white"
                                                title="Die Fassungen sind verschieden (z. B. andere Version) – alle bleiben, Fund wird ausgeblendet">≠ Verschieden – alle behalten</button>
                                        @if (str_contains($f['detail'], 'bitte von Hand'))
                                            <div class="font-sans text-amber-300">{{ \Illuminate\Support\Str::afterLast($f['detail'], "\n") }}</div>
                                        @endif
                                    </div>
                                @else
                                    <div class="text-xs whitespace-pre-line text-zinc-400">{{ $f['detail'] }}</div>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($f['link'])
                                    <a href="{{ $f['link'] }}" wire:navigate class="btn-ghost btn-sm">{{ $fixable ? 'Ansehen' : 'Im Editor' }}</a>
                                @endif
                                @if ($check === 'electronic_sub' && ! empty($f['dismiss_key']))
                                    <button wire:click="dismissAlbum(@js($f['id']))" class="btn-ghost btn-sm"
                                            title="Kein Unterstil nötig – das Album wird hier nicht mehr gemeldet (rückgängig über die Liste unten)">Electronic reicht hier</button>
                                @endif
                                @if ($fixable && $f['actions'])
                                    @if (isset($running[$f['id']]))
                                        <span class="flex items-center gap-1.5 px-2 text-xs text-violet-300"><span class="size-2 animate-pulse rounded-full bg-violet-400"></span>läuft …</span>
                                    @else
                                        <button wire:click="applyOne(@js($f['id']))" wire:loading.attr="disabled" wire:target="applyOne(@js($f['id']))"
                                                wire:confirm="„{{ $f['title'] }}“ übernehmen? {{ count($f['actions']) }} Schritt(e) – rückgängig machbar im Verlauf."
                                                class="btn-primary btn-sm">Übernehmen</button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="py-12 text-center text-zinc-500">Keine Funde. 🎉</p>
                    @endforelse
                    @if ($check === 'electronic_sub' && $dismissedCount)
                        <div class="pt-3">
                            <button wire:click="$toggle('showDismissed')" class="text-xs text-zinc-500 hover:text-zinc-300">
                                {{ $showDismissed ? '▾' : '▸' }} {{ $dismissedCount }} mit „Electronic reicht hier“
                            </button>
                            @if ($showDismissed)
                                <div class="mt-2 space-y-1.5">
                                    @forelse ($dismissed as $d)
                                        <div class="flex items-center gap-2 rounded-lg border border-zinc-800 bg-zinc-900/30 px-3 py-2 text-xs" wire:key="dis{{ $d['id'] }}">
                                            <span class="font-medium text-zinc-300">{{ $d['title'] }}</span>
                                            @if ($d['link'])
                                                <a href="{{ $d['link'] }}" wire:navigate class="text-zinc-500 hover:text-white">Im Editor</a>
                                            @endif
                                            <button wire:click="undismissAlbum(@js($d['dismiss_key']))" class="ml-auto text-zinc-500 hover:text-white">wieder melden</button>
                                        </div>
                                    @empty
                                        <p class="text-xs text-zinc-600">Derzeit keines dieser Alben ohne Unterstil im Bestand.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    @endif
                    @if ($check === 'duplicates' && $dismissedCount)
                        <div class="pt-3">
                            <button wire:click="$toggle('showDismissed')" class="text-xs text-zinc-500 hover:text-zinc-300">
                                {{ $showDismissed ? '▾' : '▸' }} {{ $dismissedCount }} als verschieden markiert
                            </button>
                            @if ($showDismissed)
                                <div class="mt-2 space-y-1.5">
                                    @forelse ($dismissed as $d)
                                        <div class="rounded-lg border border-zinc-800 bg-zinc-900/30 px-3 py-2 text-xs" wire:key="dis{{ $d['id'] }}">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-zinc-300">{{ $d['title'] }}</span>
                                                <button wire:click="undismiss(@js($d['song_key']), @js($d['fingerprints']))" class="ml-auto text-zinc-500 hover:text-white">wieder als Duplikat prüfen</button>
                                            </div>
                                            @foreach ($d['options'] as $o)
                                                <div class="mt-0.5 flex items-center gap-2 font-mono text-zinc-500">
                                                    <span class="truncate" title="{{ $o['label'] }}">{{ $o['label'] }}</span><span class="shrink-0">{{ $o['info'] }}</span>
                                                    <x-play-button :id="$o['id']" :seconds="$o['seconds']" :title="$o['title']" :artist="$o['artist']" :album="$o['album']" size="size-6" />
                                                </div>
                                            @endforeach
                                        </div>
                                    @empty
                                        <p class="text-xs text-zinc-600">Derzeit keine dieser Dateien im Bestand.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    @endif
                    @if ($total > count($findings))
                        <button wire:click="$set('limit', {{ $limit + 200 }})" class="btn-ghost w-full">Weitere anzeigen ({{ $total - count($findings) }})</button>
                    @endif
                </div>
            </div>
        </section>
    </div>

    @if ($preview)
        @php
            $shown = array_slice($preview['actions'], 0, 300);
            $hasMoves = collect($preview['actions'])->contains(fn ($a) => in_array($a['type'], ['move', 'apply']) && ! empty($a['target']));
        @endphp
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-6 backdrop-blur-sm" x-data="{ downOutside: false }" x-on:keydown.escape.window="$wire.set('preview', null)"
             x-on:mousedown.self="downOutside = true" x-on:click.self="if (downOutside) $wire.set('preview', null); downOutside = false"
             x-on:mouseup="if ($event.target !== $el) downOutside = false">
            <div class="flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl">
                <div class="flex items-center border-b border-zinc-800 px-6 py-4">
                    <h2 class="text-lg font-semibold">Vorschau: {{ $defs[$check][2] }} · {{ $preview['count'] }} Funde, {{ count($preview['actions']) }} Schritte</h2>
                    <button wire:click="$set('preview', null)" class="ml-auto text-zinc-400 hover:text-white">✕</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-3 font-mono text-xs">
                    @if ($preview['notes'] ?? [])
                        <div class="mb-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 font-sans text-sm text-amber-200">
                            {{ count($preview['notes']) }} Ziel(e) sind belegt bzw. doppelt. Unten steht pro Datei, was passiert.
                        </div>
                    @endif
                    @foreach ($shown as $ai => $a)
                        <div class="border-b border-zinc-800/70 py-1.5">
                            <span class="mr-2 inline-block w-20 text-violet-300">{{ ['apply' => 'Tags', 'move' => 'Verschieben', 'trash' => 'Papierkorb', 'delete_junk' => 'Entfernen', 'album_files' => 'Cover mit', 'replaygain' => 'ReplayGain', 'lyrics' => 'Songtext'][$a['type']] ?? $a['type'] }}</span>
                            <span class="text-zinc-400">{{ $rel($a['path'] ?? $a['from_dir'] ?? '') }}</span>
                            @if (! empty($a['target']) || ! empty($a['to_dir']))<div class="ml-22 text-blue-300">→ {{ $rel($a['target'] ?? $a['to_dir']) }}</div>@endif
                            @if (isset($preview['notes'][$ai]))<div class="ml-22 text-amber-300">⚠ Ziel belegt: {{ $onConflict === 'best' ? $preview['notes'][$ai] : ($onConflict === 'number' ? 'wird mit „(2)“ daneben abgelegt' : 'wird übersprungen') }}</div>@endif
                            @if (! empty($a['note']))<div class="ml-22 text-zinc-300">{{ $a['note'] }}</div>@endif
                            @if (! empty($a['embed_from']))<div class="ml-22 text-zinc-300">Cover einbetten ({{ basename($a['embed_from']) }})</div>@endif
                            @if (! empty($a['tags']))
                                <div class="ml-22 text-zinc-300">@foreach ($a['tags'] as $k => $v){{ strtoupper($k) }} = {{ is_array($v) ? implode(' ; ', $v) : (is_bool($v) ? ($v ? '1' : '–') : $v) }}&nbsp;&nbsp; @endforeach</div>
                            @endif
                        </div>
                    @endforeach
                    @if (count($preview['actions']) > 300)<p class="py-2 text-zinc-500">… und {{ count($preview['actions']) - 300 }} weitere.</p>@endif
                </div>
                <div class="flex items-center gap-4 border-t border-zinc-800 px-6 py-4">
                    @if ($hasMoves)
                        <label class="flex items-center gap-2 text-sm text-amber-200">
                            Wenn das Ziel schon belegt ist:
                            <select wire:model.live="onConflict" class="input-sm w-auto">
                                <option value="best">bessere Version behalten, andere in den Papierkorb</option>
                                <option value="skip">überspringen</option>
                                <option value="number">beide behalten (mit „(2)“)</option>
                            </select>
                        </label>
                    @endif
                    <button wire:click="$set('preview', null)" class="btn-ghost ml-auto">Abbrechen</button>
                    <button wire:click="confirm" class="btn-accent">Bestätigen &amp; ausführen</button>
                </div>
            </div>
        </div>
    @endif
</div>
