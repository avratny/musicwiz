{{-- Cover setzen: Vorschläge aus dem Album, aus dem Netz, hochgeladen, per URL – als Modal oder eingebettet (inline) --}}
@php
    $tile = 'group relative flex w-32 flex-col rounded-md border border-zinc-800 p-1.5 text-left hover:border-accent/60 hover:bg-accent/5 disabled:cursor-default disabled:opacity-60';
@endphp
<div>
@if ($inline || $open)
    @if (! $inline)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" wire:keydown.escape.window="close">
            <div class="absolute inset-0" wire:click="close"></div>
            <div class="relative max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
                <div class="mb-4 flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold">Cover setzen</h2>
                        @if ($info)<p class="truncate text-sm text-zinc-400">@if ($info['single'])Nur dieses Lied: {{ $info['tracks']->first()->title }}@else{{ $info['album'] ?: 'Ohne Album' }}@endif · {{ $info['artist'] }}</p>@endif
                    </div>
                    <button class="rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" wire:click="close" title="Schließen (Esc)">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
    @endif

    @if ($info)
        <div class="space-y-4">
            @if ($error)
                <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
            @endif

            {{-- im Album --}}
            @if ($local)
                <div>
                    <div class="mb-1.5 text-xs font-medium text-zinc-500">{{ $info['single'] ? 'Vorhanden' : 'Im Album' }}</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($local as $l)
                            <button type="button" class="{{ $tile }}" @disabled($l['kind'] === 'new')
                                    @if ($l['kind'] === 'embedded') wire:click="useTrack({{ $l['id'] }})" @elseif ($l['kind'] === 'folder') wire:click="useFolder({{ $l['id'] }})" @endif
                                    title="{{ $l['kind'] === 'new' ? 'bereits gesetzt' : 'Für alle Lieder des Albums übernehmen' }}">
                                <img src="{{ $l['preview'] }}" loading="lazy" alt="" class="aspect-square w-full rounded bg-zinc-800 object-cover" onerror="noCover(this)">
                                <span class="mt-1 truncate text-[11px] font-medium text-zinc-300">{{ $l['label'].($l['count'] ? ' · '.$l['count'].' '.($l['count'] === 1 ? 'Lied' : 'Lieder') : '') }}</span>
                                <span @class(['text-[11px] tabular-nums', 'text-red-400' => min($l['width'], $l['height']) < $minSize, 'text-zinc-500' => min($l['width'], $l['height']) >= $minSize])>{{ $l['width'] }}×{{ $l['height'] }} px</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- aus dem Netz --}}
            <div>
                <div class="mb-1.5 flex items-center gap-3">
                    <span class="text-xs font-medium text-zinc-500">Aus dem Netz</span>
                    <span class="text-[11px] text-zinc-600">Cover Art Archive · Deezer · iTunes</span>
                    @if (! $searched)
                        <button type="button" class="btn py-0.5 text-xs" wire:click="search" wire:loading.attr="disabled" wire:target="search">Vorschläge suchen</button>
                    @endif
                    <span class="text-xs text-zinc-500" wire:loading wire:target="search">suche …</span>
                </div>
                @if ($searched)
                    @if ($shownSuggestions)
                        <div class="flex flex-wrap gap-2">
                            @foreach ($shownSuggestions as $s)
                                <button type="button" class="{{ $tile }}" wire:click="useUrl(@js($s['url']))" wire:loading.attr="disabled" title="{{ $s['url'] }}">
                                    <img src="{{ $s['preview'] }}" loading="lazy" alt="" referrerpolicy="no-referrer" class="aspect-square w-full rounded bg-zinc-800 object-cover" onerror="noCover(this)">
                                    <span class="mt-1 truncate text-[11px] font-medium text-zinc-300" title="{{ $s['title'] }}">{{ $s['title'] }}</span>
                                    <span class="truncate text-[11px] text-zinc-500" title="{{ $s['artist'] }}">{{ $s['artist'].($s['year'] ? ' · '.$s['year'] : '') }}</span>
                                    <span class="text-[11px] text-zinc-500">{{ $s['service'] }} · {{ $s['size'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-zinc-500">Nichts gefunden – vielleicht per URL?</p>
                    @endif
                    @foreach ($searchErrors as $e)
                        <p class="mt-1 text-xs text-amber-300">{{ $e }}</p>
                    @endforeach
                @endif
            </div>

            @if ($min)
                <p class="text-[11px] text-zinc-500">{{ 'Nur Bilder ab '.$min.' px'.($hidden ? ' – '.$hidden.' kleinere ausgeblendet' : '').'.' }} Größe „bis …“ wird beim Übernehmen geprüft.</p>
            @endif

            {{-- hochladen: auswählen oder hineinziehen --}}
            <div x-data="{ over: false }" @dragover.prevent="over = true" @dragleave.prevent="over = false"
                 @drop.prevent="over = false; $event.dataTransfer.files.length && $wire.upload('upload', $event.dataTransfer.files[0])"
                 :class="over ? 'border-accent bg-accent/5' : 'border-zinc-700'"
                 class="flex items-center gap-2 rounded-md border border-dashed px-2 py-2">
                <span class="w-12 shrink-0 text-xs font-medium text-zinc-500">Datei</span>
                <span class="flex-1 text-sm text-zinc-400">Bild hierher ziehen oder</span>
                <label class="btn shrink-0 cursor-pointer">Bild hochladen …<input type="file" accept="image/*" wire:model="upload" class="hidden"></label>
            </div>
            @error('upload')
                <p class="text-xs text-red-300">{{ $message }}</p>
            @enderror

            {{-- per URL --}}
            <form wire:submit="useUrl" class="flex items-center gap-2">
                <span class="w-12 shrink-0 text-xs font-medium text-zinc-500">URL</span>
                <input type="url" wire:model="url" placeholder="https://… (Bild in voller Größe)" class="field flex-1">
                <button type="submit" class="btn shrink-0" wire:loading.attr="disabled" wire:target="useUrl">Übernehmen</button>
            </form>
            <p class="text-[11px] text-zinc-500" wire:loading wire:target="upload,useUrl,useTrack,useFolder">lade und setze …</p>
            <p class="text-[11px] text-zinc-500">{{ $info['single'] ? 'Gilt nur für dieses Lied (Einzeltitel)' : 'Gilt für alle '.$info['tracks']->count().' '.($info['tracks']->count() === 1 ? 'Lied' : 'Lieder').' des Albums' }} – nur in der Datenbank (unter Änderungen zurücksetzbar), in die Dateien kommt es erst beim Schreiben.</p>
        </div>
    @endif

    @if (! $inline)
            </div>
        </div>
    @endif
@endif
</div>
