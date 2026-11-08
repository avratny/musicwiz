<div @if ($active) wire:poll.2s @else wire:poll.15s @endif class="space-y-2 p-3">
    @if ($scan['running'] ?? false)
        <div class="rounded-lg border border-blue-500/30 bg-blue-500/10 p-3 text-xs">
            <div class="flex items-center gap-2 font-medium text-blue-200">
                <span class="size-2 animate-pulse rounded-full bg-blue-400"></span>
                Scan {{ ['inbox' => 'Inbox', 'library' => 'Lib', 'blackhole' => 'Blackhole'][$scan['location']] ?? 'Lib' }} · {{ $scan['phase'] ?? '' }}
            </div>
            @if (($scan['total'] ?? 0) > 0)
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                    <div class="h-full bg-gradient-to-r from-blue-500 to-violet-500" style="width: {{ min(100, round(($scan['done'] ?? 0) / max(1, $scan['total']) * 100)) }}%"></div>
                </div>
                <div class="mt-1 text-zinc-400">{{ $scan['done'] ?? 0 }} / {{ $scan['total'] }} Dateien</div>
            @endif
        </div>
    @endif
    @foreach ($ops as $op)
        <a href="{{ route('history') }}" wire:navigate class="block rounded-lg border border-violet-500/30 bg-violet-500/10 p-3 text-xs">
            <div class="flex items-center gap-2 font-medium text-violet-200">
                <span class="size-2 animate-pulse rounded-full bg-violet-400"></span>
                <span class="truncate" title="{{ $op->description }}">{{ $op->description }}</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-800">
                <div class="h-full bg-gradient-to-r from-blue-500 to-violet-500" style="width: {{ min(100, round(($op->done + $op->failed) / max(1, $op->total) * 100)) }}%"></div>
            </div>
        </a>
    @endforeach
    @if ($recent && $ops->isEmpty())
        <a href="{{ route('history') }}" wire:navigate class="block rounded-lg border p-3 text-xs {{ $recent->status === 'failed' ? 'border-red-500/30 bg-red-500/10 text-red-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' }}">
            {{ $recent->status === 'failed' ? 'Mit Fehlern beendet' : 'Erledigt' }}: <span class="text-zinc-300">{{ $recent->description }}</span>
        </a>
    @endif
    {{-- Protokoll der Dateiänderungen --}}
    @php
        $rel = fn ($p) => str_starts_with((string) $p, $base.'/') ? substr($p, strlen($base) + 1) : (string) $p;
        $labels = [
            'tags' => ['Tags', 'text-blue-300'],
            'move' => ['Verschoben', 'text-violet-300'],
            'file' => ['Cover', 'text-violet-300'],
            'trash' => ['Papierkorb', 'text-amber-300'],
            'delete' => ['Entfernt', 'text-amber-300'],
            'download' => ['Download', 'text-emerald-300'],
            'error' => ['Fehler', 'text-red-300'],
        ];
    @endphp
    <div class="border-t border-zinc-800 pt-2">
        <div class="flex items-center px-1 pb-1 text-[11px] font-medium tracking-wide text-zinc-500 uppercase">
            <span>Protokoll</span>
            <a href="{{ route('history') }}" wire:navigate class="ml-auto normal-case tracking-normal hover:text-zinc-300">alle →</a>
        </div>
        <a href="{{ route('settings', ['tab' => 'auto']) }}" wire:navigate class="block px-1 pb-1 text-[11px] {{ $autoCount ? 'text-blue-300' : 'text-zinc-600' }} hover:underline">
            {{ $autoCount ? $autoCount.' Aufräum-Automatik(en) aktiv' : 'Nichts passiert automatisch.' }}
        </a>
        @if ($log->isEmpty())
            <div class="px-1 text-[11px] text-zinc-600">Noch keine Änderungen.</div>
        @else
            <ul class="max-h-56 space-y-0.5 overflow-y-auto pr-1 text-[11px] leading-snug">
                @foreach ($log as $e)
                    @php
                        [$label, $color] = $labels[$e->kind] ?? [$e->kind, 'text-zinc-400'];
                        $undo = $e->operation?->type === 'undo';
                        $path = $e->kind === 'error' ? $e->error : ($e->new_path ?: $e->old_path);
                        $detail = match ($e->kind) {
                            'move', 'trash' => $rel($e->old_path)."\n→ ".$rel($e->new_path),
                            'tags' => $rel($e->old_path)."\n".collect($e->new_tags ?? [])->map(fn ($v, $k) => strtoupper($k).' = '.(is_array($v) ? implode('; ', $v) : (is_bool($v) ? ($v ? '1' : '–') : $v)))->implode("\n"),
                            'error' => $e->error,
                            default => $rel($path),
                        };
                    @endphp
                    <li wire:key="log{{ $e->id }}" class="rounded px-1 py-0.5 hover:bg-zinc-800/60" title="{{ $detail }}">
                        <div class="flex items-baseline gap-1.5">
                            <span class="{{ $color }} shrink-0 font-medium">{{ $undo ? '↺ ' : '' }}{{ $label }}</span>
                            <span class="ml-auto shrink-0 text-zinc-600">{{ $e->created_at->format('H:i') }}</span>
                        </div>
                        <div class="truncate text-zinc-400">{{ $e->kind === 'error' ? $e->error : basename((string) $path) }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
