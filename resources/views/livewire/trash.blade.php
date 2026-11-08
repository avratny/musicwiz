@php
    $rel = fn ($p) => str_starts_with((string) $p, $base.'/') ? substr($p, strlen($base) + 1) : $p;
    $size = fn ($b) => $b >= 1e9 ? number_format($b / 1e9, 1, ',', '.').' GB' : ($b >= 1e6 ? number_format($b / 1e6, 1, ',', '.').' MB' : number_format($b / 1e3, 0, ',', '.').' KB');
    $chosenCount = count($selected);
@endphp
<div class="flex h-full flex-col overflow-hidden" @if ($running) wire:poll.2s @endif>
    <x-page-header title="Papierkorb" :subtitle="count($files).' Datei(en) · '.$size($totalSize).' · '.$root">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Suchen …" class="input w-64">
    </x-page-header>

    <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-zinc-800 px-6 py-3 text-sm">
        <button wire:click="selectAll" class="btn-ghost btn-sm" @disabled(! $files)>Alle markieren</button>
        @if ($chosenCount)
            <button wire:click="$set('selected', [])" class="btn-ghost btn-sm">Auswahl aufheben</button>
        @endif
        <span class="text-zinc-400">{{ $chosenCount }} ausgewählt</span>
        <label class="ml-auto flex items-center gap-2 text-zinc-300">
            Ziel belegt:
            <select wire:model.live="onConflict" class="input-sm w-auto">
                <option value="skip">überspringen</option>
                <option value="number">daneben mit „(2)“</option>
                <option value="best">bessere Version behalten</option>
            </select>
        </label>
        <button wire:click="restore" wire:confirm="{{ $chosenCount }} Datei(en) an ihren ursprünglichen Ort zurücklegen?" class="btn-primary btn-sm" @disabled(! $chosenCount || $running)>Wiederherstellen</button>
        <button wire:click="deleteForever" wire:confirm="{{ $chosenCount }} Datei(en) ENDGÜLTIG löschen? Das lässt sich nicht rückgängig machen." class="btn-danger btn-sm" @disabled(! $chosenCount || $running)>Endgültig löschen …</button>
    </div>

    @if ($done)
        <div class="shrink-0 px-6 pt-3">
            <div class="flex items-start gap-3 rounded-lg border px-4 py-2 text-sm whitespace-pre-line {{ str_contains($done, '⚠') ? 'border-amber-500/30 bg-amber-500/10 text-amber-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' }}">
                <span class="flex-1">{{ $done }}</span>
                <a href="{{ route('history') }}" wire:navigate class="shrink-0 underline">Verlauf</a>
                <button wire:click="$set('done', null)" class="shrink-0 opacity-70 hover:opacity-100">✕</button>
            </div>
        </div>
    @endif

    <div class="min-h-0 flex-1 overflow-y-auto">
        @if (! $files)
            <div class="grid h-full place-items-center text-center text-zinc-500">
                <div>
                    <p class="text-lg text-zinc-300">Der Papierkorb ist leer.</p>
                    <p class="mt-1 text-sm">Aussortiertes landet hier – nichts wird automatisch endgültig gelöscht.</p>
                </div>
            </div>
        @else
            <table class="w-full text-sm">
                <thead><tr>
                    <th class="th w-10"></th><th class="th w-10"></th>
                    <th class="th">Ursprünglicher Ort</th><th class="th w-56">Grund</th><th class="th w-24 text-right">Größe</th><th class="th w-36">Aussortiert</th>
                </tr></thead>
                <tbody>
                @foreach ($files as $f)
                    <tr wire:key="t{{ md5($f['rel']) }}" class="hover:bg-zinc-900/60 {{ in_array($f['rel'], $selected, true) ? 'bg-blue-500/5' : '' }}">
                        <td class="td"><input type="checkbox" wire:model.live="selected" value="{{ $f['rel'] }}" class="rounded border-zinc-600 bg-zinc-800"></td>
                        <td class="td">
                            @if ($f['audio'])
                                @php $track = ['id' => crc32($f['rel']), 'src' => route('trash.file', ['f' => $f['rel']]), 'title' => pathinfo($f['rel'], PATHINFO_FILENAME), 'artist' => 'Papierkorb', 'album' => '', 'cover' => null]; @endphp
                                <button x-data x-on:click="$store.player.play(@js($track))" class="grid size-7 place-items-center rounded-full bg-zinc-800 text-zinc-300 hover:bg-blue-600 hover:text-white" title="Reinhören">
                                    <svg class="size-3.5 translate-x-px" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                </button>
                            @endif
                        </td>
                        <td class="td">
                            <div class="truncate font-mono text-xs text-zinc-200" title="{{ $f['original'] }}">{{ $rel($f['original']) }}</div>
                            @if ($f['occupied'] ?? false)<div class="text-[11px] text-amber-300">am ursprünglichen Ort liegt inzwischen eine andere Datei</div>@endif
                        </td>
                        <td class="td text-xs text-zinc-400"><span class="line-clamp-2" title="{{ $f['reason'] }}">{{ $f['reason'] ?? '—' }}</span></td>
                        <td class="td text-right text-xs text-zinc-400 tabular-nums">{{ $size($f['size']) }}</td>
                        <td class="td text-xs text-zinc-400">{{ \Illuminate\Support\Carbon::createFromTimestamp($f['date'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
