@php
    $rel = fn ($p) => str_starts_with((string) $p, $base) ? substr($p, strlen($base)) : (string) $p;
    $show = fn ($v) => is_array($v) ? implode(' ; ', $v) : (is_bool($v) ? ($v ? '1' : '–') : ((string) $v === '' ? '∅' : $v));
@endphp
<div class="flex h-full flex-col overflow-hidden" @if ($active->isNotEmpty() || $watching) wire:poll.2s @endif>
    <x-page-header title="Änderungen schreiben" subtitle="Vorgemerkt in Aufräumen, Album-Editor und Künstlerverzeichnis – steht schon in MusicWiz, aber noch nicht in den Dateien. Beim Schreiben je Datei einmal Tags, dann an den Ort, den die fertigen Tags ergeben.">
        @if ($trackTotal)
            <button wire:click="discard" data-confirm-label="Alles verwerfen" data-confirm-danger
                    wire:confirm="Alle {{ $trackTotal }} vorgemerkten Änderungen verwerfen? Die Dateien sind nicht betroffen – MusicWiz zeigt danach wieder ihren Stand."
                    class="btn-ghost" @disabled($active->isNotEmpty())>Alle verwerfen</button>
            <button wire:click="write" data-confirm-label="Schreiben"
                    wire:confirm="{{ $trackTotal }} Titel in {{ $albumTotal }} Alben schreiben? Tags in die Dateien, danach an den Regelort (Cover und Extras ziehen mit). Rückgängig machbar im Verlauf."
                    class="btn-primary" @disabled($active->isNotEmpty())>
                <span wire:loading.remove wire:target="write">Alle schreiben ({{ $trackTotal }})</span><span wire:loading wire:target="write">bereitet vor …</span>
            </button>
        @endif
    </x-page-header>

    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        @foreach ($active as $op)
            <div class="mb-3 rounded-lg border border-violet-500/30 bg-violet-500/10 p-3 text-sm text-violet-200" wire:key="op{{ $op->id }}">
                <div class="flex items-center gap-2"><span class="size-2 animate-pulse rounded-full bg-violet-400"></span>{{ $op->description }}<span class="ml-auto tabular-nums">{{ $op->done + $op->failed }} / {{ $op->total }}</span></div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-800"><div class="h-full bg-gradient-to-r from-blue-500 to-violet-500" style="width: {{ min(100, round(($op->done + $op->failed) / max(1, $op->total) * 100)) }}%"></div></div>
            </div>
        @endforeach
        @if ($done)
            <div class="mb-3 flex items-start gap-3 rounded-lg border px-4 py-2 text-sm whitespace-pre-line {{ str_contains($done, '⚠') ? 'border-amber-500/30 bg-amber-500/10 text-amber-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' }}">
                <span class="flex-1">{{ $done }}</span>
                <a href="{{ route('history') }}" wire:navigate class="shrink-0 underline">Verlauf</a>
                <button wire:click="$set('done', null)" class="shrink-0 opacity-70 hover:opacity-100">✕</button>
            </div>
        @endif

        @forelse ($albums as $al)
            <div class="card mb-3 p-4" wire:key="al{{ $al['id'] }}">
                <div class="flex items-center gap-3">
                    <a href="{{ route('library.album', $al['id']) }}" wire:navigate class="font-medium text-zinc-100 hover:underline">{{ $al['title'] ?: 'Ohne Album' }}</a>
                    <span class="text-xs text-zinc-500">{{ $al['count'] }} Titel</span>
                    <button wire:click="discard({{ $al['id'] }})" class="ml-auto text-xs text-zinc-500 hover:text-red-300" @disabled($active->isNotEmpty())>verwerfen</button>
                    <button wire:click="write({{ $al['id'] }})" class="btn-ghost btn-sm" @disabled($active->isNotEmpty())>schreiben</button>
                </div>
                <table class="mt-2 w-full text-xs">
                    @foreach ($al['rows'] as $r)
                        @foreach ($r['before'] as $k => $old)
                            <tr class="align-top">
                                <td class="w-64 truncate pr-3 text-zinc-400" title="{{ $r['file'] }}">{{ $loop->first ? $r['file'] : '' }}</td>
                                <td class="w-28 pr-3 text-zinc-500">{{ strtoupper($k) }}</td>
                                <td class="max-w-80 truncate pr-3 text-red-300/80" title="{{ $show($old) }}">{{ $show($old) }}</td>
                                <td class="max-w-80 truncate text-emerald-200" title="{{ $show($r['after'][$k] ?? null) }}">→ {{ $show($r['after'][$k] ?? null) }}</td>
                            </tr>
                        @endforeach
                        @if ($r['cover'] ?? null)
                            <tr class="align-top">
                                <td class="w-64 truncate pr-3 text-zinc-400">{{ $r['before'] ? '' : $r['file'] }}</td>
                                <td class="w-28 pr-3 text-zinc-500">COVER</td>
                                <td colspan="2" class="text-violet-300">{{ $r['cover'] }}</td>
                            </tr>
                        @endif
                        @if ($r['to'])
                            <tr class="align-top">
                                <td class="w-64 truncate pr-3 text-zinc-400">{{ $r['before'] || ($r['cover'] ?? null) ? '' : $r['file'] }}</td>
                                <td class="w-28 pr-3 text-zinc-500">{{ $r['rename'] ? 'Dateiname' : 'Ordner' }}</td>
                                <td class="max-w-80 truncate pr-3 text-red-300/80" title="{{ $rel($r['from']) }}">{{ $r['rename'] ? $r['file'] : $rel($r['from']) }}</td>
                                <td class="max-w-80 truncate text-blue-300" title="{{ $rel($r['to']) }}">→ {{ $r['rename'] ?: $rel($r['to']) }}</td>
                            </tr>
                        @endif
                    @endforeach
                </table>
                @if ($al['count'] > count($al['rows']))<div class="mt-1 text-xs text-zinc-500">… und {{ $al['count'] - count($al['rows']) }} weitere Titel</div>@endif
            </div>
        @empty
            <div class="grid h-full place-items-center text-center text-zinc-500">
                <div>
                    <p>Nichts vorgemerkt – alles steht so in den Dateien.</p>
                    <p class="mt-1 text-sm">Behebungen im <a href="{{ route('cleanup') }}" wire:navigate class="underline">Aufräumen</a>, Speichern im Album-Editor (Lib) und Entscheidungen im Künstlerverzeichnis landen erst hier.</p>
                </div>
            </div>
        @endforelse
        @if ($albumTotal > count($albums))
            <div class="flex items-center gap-3">
                <button wire:click="loadMore" class="btn-ghost">Mehr anzeigen</button>
                <span class="text-sm text-zinc-500">noch {{ $albumTotal - count($albums) }} Alben</span>
            </div>
        @endif
    </div>
</div>
