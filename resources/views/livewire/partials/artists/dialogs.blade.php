{{-- Verzeichnis: Dialoge (Umbenennen, Ist derselbe wie, In Einzelkünstler trennen, Neuer Künstler) --}}
@php
    $modal = 'fixed inset-0 z-50 grid place-items-center bg-black/70 p-6 backdrop-blur-sm';
    $box = 'w-full max-w-xl rounded-2xl border border-zinc-700 bg-zinc-900 p-5 shadow-2xl';
@endphp

@if ($newOpen)
    <div class="{{ $modal }}" x-data x-on:keydown.escape.window="$wire.set('newOpen', false)" x-on:click.self="$wire.set('newOpen', false)">
        <div class="{{ $box }} max-w-md">
            <h3 class="text-lg font-semibold">Neuer Künstler</h3>
            <p class="mt-1 text-sm text-zinc-400">Leeren Eintrag anlegen – MusicBrainz-ID, Bild und Genre danach im Eintrag. Mit Treffern und Bildern geht es schneller unter „Schnell erfassen“.</p>
            <input wire:model="newName" wire:keydown.enter="create" x-init="$nextTick(() => $el.focus())" placeholder="Name" class="input mt-3">
            <div class="mt-4 flex justify-end gap-2">
                <button wire:click="$set('newOpen', false)" class="btn-ghost">Abbrechen</button>
                <button wire:click="create" class="btn-primary">Anlegen</button>
            </div>
        </div>
    </div>
@endif

@if ($artist)
    {{-- Bildauswahl: öffnet sofort im Browser (an imagesOpen gekoppelt), die Suche läuft danach mit Wartebalken --}}
    <div x-data="{ open: $wire.entangle('imagesOpen') }" x-show="open" x-cloak x-transition.opacity class="{{ $modal }}"
         x-on:keydown.escape.window="open = false" x-on:click.self="open = false">
        <div class="{{ $box }} max-w-4xl">
            <div class="flex items-center gap-3">
                <h3 class="flex-1 text-lg font-semibold">Bild für „{{ $artist->name }}“</h3>
                <button x-on:click="open = false" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <p class="mt-1 text-sm text-zinc-400">Klick auf ein Bild übernimmt es. Quellen: TheAudioDB, Spotify, Deezer, Fanart.tv, Discogs – je nach Zugangsdaten.</p>
            {{-- Wartebalken, solange gesucht wird --}}
            <div wire:loading.block wire:target="findImages" class="mt-5">
                <div class="mb-2 text-sm text-violet-300">sucht Bilder bei den Diensten …</div>
                <div class="progress-indeterminate h-1.5 overflow-hidden rounded-full bg-zinc-800"><div class="h-full w-1/3 rounded-full bg-gradient-to-r from-blue-500 to-violet-500"></div></div>
            </div>
            <div wire:loading.remove wire:target="findImages">
                @if ($imageCandidates)
                    <div class="mt-4 grid max-h-[60vh] grid-cols-3 gap-3 overflow-y-auto sm:grid-cols-4 lg:grid-cols-5">
                        @foreach ($imageCandidates as $i => $c)
                            <button wire:click="useImage({{ $i }})" wire:loading.attr="disabled" wire:target="useImage" class="group text-left">
                                <img src="{{ $c['thumb'] }}" loading="lazy" class="aspect-square w-full rounded-lg object-cover ring-1 ring-zinc-700 group-hover:ring-2 group-hover:ring-blue-500">
                                <span class="mt-1 block truncate text-xs text-zinc-400" title="{{ $c['source'] }} {{ $c['size'] }}">{{ $c['source'] }} {{ $c['size'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @elseif ($lookupError)
                    <p class="mt-4 text-sm text-amber-300">{{ $lookupError }}</p>
                @endif
                <div wire:loading.block wire:target="useImage" class="mt-3 text-sm text-violet-300">übernimmt das Bild …</div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-800 pt-4">
                <label class="btn-ghost btn-sm cursor-pointer">Eigenes Bild hochladen<input type="file" accept="image/*" wire:model="imageUpload" class="hidden"></label>
                <input type="url" wire:model="imageUrl" wire:keydown.enter="loadImageUrl" placeholder="oder Bild-URL …" class="input-sm w-72 text-xs">
                <button wire:click="loadImageUrl" class="btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="loadImageUrl"><span wire:loading.remove wire:target="loadImageUrl">Laden</span><span wire:loading wire:target="loadImageUrl">…</span></button>
                <button wire:click="findImages" class="btn-ghost btn-sm">Neu suchen</button>
                @if ($artist->image)
                    <button wire:click="removeImage" data-confirm-label="Entfernen" wire:confirm="Bild entfernen? Die Datei wandert in den Papierkorb." class="btn-ghost btn-sm ml-auto hover:text-red-300">Aktuelles Bild entfernen</button>
                @endif
            </div>
            @error('imageUrl')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
        </div>
    </div>
@endif

@if ($artist && $mergeOpen)
    <div class="{{ $modal }}" x-data x-on:keydown.escape.window="$wire.set('mergeOpen', false)" x-on:click.self="$wire.set('mergeOpen', false)">
        <div class="{{ $box }}">
            <h3 class="text-lg font-semibold">Ist derselbe wie …</h3>
            <div class="mt-1 text-sm text-zinc-400">Welche Einträge sind in Wahrheit <strong class="text-zinc-200">„{{ $artist->name }}“</strong>? Sie werden zu Schreibweisen dieses Künstlers; Varianten, IDs, Genre und Bild werden übernommen, wo hier noch nichts steht.</div>
            <input type="search" wire:model.live.debounce.300ms="mergeQ" placeholder="Anderen Eintrag suchen …" class="input-sm mt-3 w-72">
            <div class="mt-2 max-h-72 space-y-0.5 overflow-y-auto">
                @forelse ($mergeCandidates as $c)
                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-zinc-800/60" wire:key="mc{{ $c->id }}">
                        <input type="checkbox" wire:model.live="mergeIds" value="{{ $c->id }}" class="size-4 rounded border-zinc-600 bg-zinc-800 text-violet-500">
                        @if ($c->imageUrl())<img src="{{ $c->imageUrl() }}" class="size-6 rounded-full object-cover">@endif
                        <span class="text-zinc-200">{{ $c->name }}</span>
                        <span class="text-xs text-zinc-500">{{ $c->aliases_count ? $c->aliases_count.' Schreibweise(n)' : '' }}{{ $c->mbid ? ' · MusicBrainz' : '' }}</span>
                    </label>
                @empty
                    <p class="px-2 py-1 text-sm text-zinc-500">{{ trim($mergeQ) === '' ? 'Keine ähnlichen Einträge – oben suchen.' : 'Nichts gefunden.' }}</p>
                @endforelse
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button wire:click="$set('mergeOpen', false)" class="btn-ghost">Abbrechen</button>
                <button wire:click="merge" @disabled(! $mergeIds) data-confirm-label="Zusammenführen" wire:confirm="Die angehakten Einträge in „{{ $artist->name }}“ aufgehen lassen? Ihre Namen werden Schreibweisen von „{{ $artist->name }}“; die Dateien bleiben vorerst unverändert." class="btn-primary">Zusammenführen ({{ count($mergeIds) }})</button>
            </div>
        </div>
    </div>
@endif

@if ($artist && $splitInto)
    <div class="{{ $modal }}" x-data x-on:keydown.escape.window="$wire.set('splitInto', null)" x-on:click.self="$wire.set('splitInto', null)">
        <div class="{{ $box }}">
            <h3 class="text-lg font-semibold">„{{ $artist->name }}“ in Einzelkünstler trennen</h3>
            <div class="mt-1 text-sm text-zinc-400">Ist es eine Zusammenarbeit, wird der Eintrag aus dem Verzeichnis genommen und künftig getrennt – auch seine Kombi-Schreibweisen. Die Tags (ARTISTS) der Titel werden vorgemerkt, neue Einzelnamen kannst du danach erfassen.</div>
            <div class="mt-3 space-y-1.5">
                @foreach ($splitInto['parts'] as $i => $p)
                    @php $h = $splitIntoHints[$i] ?? null; @endphp
                    <div wire:key="sip{{ $i }}">
                        <div class="flex items-center gap-2">
                            <input wire:model.live.debounce.400ms="splitInto.parts.{{ $i }}" class="input-sm w-64">
                            @if ($h)
                                <span class="text-xs {{ ['known' => 'text-emerald-300', 'collection' => 'text-blue-300', 'new' => 'text-amber-300'][$h['status']] }}">{{ $h['status'] === 'known' ? '✓' : ($h['status'] === 'new' ? '⚠' : '•') }} {{ $h['label'] }}</span>
                            @endif
                            @if (count($splitInto['parts']) > 2)<button wire:click="removeSplitIntoPart({{ $i }})" class="text-zinc-500 hover:text-red-300">✕</button>@endif
                        </div>
                        @if ($h && $h['suggestions'])
                            <div class="mt-1 ml-1 flex flex-wrap items-center gap-1.5 text-xs">
                                <span class="text-zinc-500">Meintest du:</span>
                                @foreach ($h['suggestions'] as $s)
                                    <button wire:click="useSplitIntoSuggestion({{ $i }}, @js($s['name']))" class="rounded border border-zinc-700 px-2 py-0.5 text-zinc-200 hover:border-blue-500 hover:text-white"
                                            title="{{ $s['source'] }}">{{ $s['name'] }}<span class="text-zinc-500">{{ $s['count'] ? ' · '.$s['count'].' Titel' : '' }}{{ $s['source'] === 'MusicBrainz' ? ' · MusicBrainz' : '' }}</span></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
                <button wire:click="addSplitIntoPart" class="text-xs text-zinc-400 hover:text-white">+ weiterer Künstler</button>
            </div>
            @if ($splitInto['error'])<p class="mt-2 text-sm text-amber-300">{{ $splitInto['error'] }}</p>@endif
            <div class="mt-4 flex justify-end gap-2">
                <button wire:click="$set('splitInto', null)" class="btn-ghost">Abbrechen</button>
                <button wire:click="saveSplitInto" data-confirm-label="Trennen" wire:confirm="„{{ $artist->name }}“ aus dem Verzeichnis nehmen und künftig in die Einzelkünstler trennen? Die Dateien ändern sich erst mit „Änderungen schreiben“." class="btn-primary">Trennen</button>
            </div>
        </div>
    </div>
@endif
