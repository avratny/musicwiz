{{-- Neue Künstler: einer nach dem anderen erfassen (Schreibweise, MusicBrainz, Discogs, Bild, Genre) --}}
<div class="flex min-h-0 flex-1 overflow-hidden">
    {{-- Warteschlange --}}
    <div class="flex w-72 shrink-0 flex-col border-r border-zinc-800">
        <div class="border-b border-zinc-800 px-4 py-3 text-sm text-zinc-400">
            <span class="font-semibold text-zinc-200">{{ count($wizQueue) }}</span> Namen noch nicht im Verzeichnis
            @if ($wizSkipped)<span class="text-zinc-500"> · {{ count($wizSkipped) }} übersprungen</span>@endif
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto p-2">
            @foreach (array_slice($wizQueue, 0, 300, true) as $qName => $qCount)
                <button wire:click="wizOpen(@js($qName))" wire:key="wq{{ md5($qName) }}"
                        class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm {{ $qName === $wizName ? 'bg-blue-600/20 text-blue-100 ring-1 ring-blue-500/40' : 'text-zinc-300 hover:bg-zinc-800' }}">
                    <span class="flex-1 truncate" title="{{ $qName }}">{{ $qName }}</span>
                    <span class="text-xs text-zinc-500">{{ $qCount }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        @if ($wizName === null)
            <div class="grid h-full place-items-center text-center text-zinc-400">
                <div>
                    <p class="text-lg text-zinc-300">Alle Künstler aus dem Bestand stehen im Verzeichnis.</p>
                    @if ($wizSkipped)<p class="mt-1 text-sm">{{ count($wizSkipped) }} übersprungen – sie kommen beim nächsten Öffnen der Seite wieder.</p>@endif
                </div>
            </div>
        @else
            {{-- wire:key pro Name: beim Wechsel startet x-init das Nachschlagen für den neuen Namen --}}
            <div class="max-w-5xl space-y-5" wire:key="wiz{{ md5($wizName) }}" x-data x-init="$wire.wizLookup()">
                <div class="flex items-start gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs tracking-wide text-zinc-500 uppercase">Neuer Künstler · {{ $wizCount }} {{ $wizCount === 1 ? 'Datei' : 'Dateien' }} im Bestand</div>
                        <h2 class="truncate text-2xl font-semibold" title="{{ $wizName }}">{{ $wizName }}</h2>
                    </div>
                    <button wire:click="wizSkip" class="btn-ghost">Überspringen</button>
                    <button wire:click="wizSave" wire:loading.attr="disabled" class="btn-primary">Speichern &amp; weiter</button>
                </div>

                @if ($wizCombined)
                    <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
                        Sieht nach mehreren Künstlern aus („&“, „und“, „,“ …). Getrennt wird nie automatisch – ist es ein Duo bzw. eine Band, einfach speichern; sonst überspringen und unter „Aufräumen › Kombi“ entscheiden.
                    </div>
                @endif

                {{-- Lieder zum Erkennen --}}
                @if ($wizTracks)
                    <div class="flex flex-wrap gap-2">
                        @foreach ($wizTracks as $t)
                            <div class="flex items-center gap-2 rounded-lg border border-zinc-800 px-2 py-1 text-xs" wire:key="wt{{ $t['id'] }}">
                                <x-play-button :id="$t['id']" :seconds="$t['seconds']" :title="$t['title']" :artist="$t['artist']" :album="$t['album']" size="size-6" />
                                <span class="max-w-56 truncate" title="{{ $t['title'] }} – {{ $t['album'] }}">{{ $t['title'] }} <span class="text-zinc-500">· {{ $t['album'] }}</span></span>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- 1. Schreibweise --}}
                <section class="card space-y-3 p-4">
                    <h3 class="text-sm font-semibold text-zinc-300">1 · Schreibweise</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <input wire:ref="wizFinal" wire:model.blur="wizFinal" wire:keydown.enter="wizSearchAgain($event.target.value)" class="input w-96 font-semibold">
                        {{-- immer sichtbar: nimmt den aktuellen Feldinhalt mit (das Feld meldet sich sonst erst beim Verlassen) --}}
                        <button wire:click="wizSearchAgain($refs.wizFinal.value)" class="btn-ghost btn-sm" title="bei MusicBrainz und Discogs mit dieser Schreibweise suchen (oder Enter im Feld)">
                            <span wire:loading.remove wire:target="wizSearchAgain">Mit dieser Schreibweise suchen</span><span wire:loading wire:target="wizSearchAgain">sucht …</span>
                        </button>
                        @if ($wizSearched !== $wizName)
                            <span class="text-xs text-zinc-500">gesucht mit „{{ $wizSearched }}“</span>
                        @endif
                        @if ($wizFinal !== $wizName)
                            <span class="text-xs text-zinc-400">„{{ $wizName }}“ wird als Schreibweise gespeichert</span>
                            <button wire:click="$set('wizFinal', @js($wizName))" class="text-xs text-blue-400 hover:underline">zurücksetzen</button>
                        @endif
                        @if ($wizMbName && \App\Support\ArtistGroupAnalyzer::cleanMbName($wizMbName) !== $wizFinal)
                            <button wire:click="$set('wizFinal', @js(\App\Support\ArtistGroupAnalyzer::cleanMbName($wizMbName)))" class="badge-blue hover:bg-blue-500/30">MusicBrainz schreibt „{{ \App\Support\ArtistGroupAnalyzer::cleanMbName($wizMbName) }}“ – übernehmen</button>
                        @endif
                    </div>
                    @if ($wizRelated)
                        <div class="text-sm">
                            <span class="text-zinc-400">Ähnliche Namen im Bestand, noch nicht erfasst – angehakte werden als Schreibweisen mitgespeichert:</span>
                            <div class="mt-1.5 space-y-1">
                                @foreach ($wizRelated as $r)
                                    <label class="flex items-center gap-2 rounded px-1 py-0.5 hover:bg-zinc-800/60" wire:key="wr{{ $r['key'] }}">
                                        <input type="checkbox" wire:model.live="wizTake.{{ $r['key'] }}" class="rounded border-zinc-600 bg-zinc-800">
                                        @if ($r['track'])
                                            <x-play-button :id="$r['track']['id']" :seconds="$r['track']['seconds']" :title="$r['track']['title']" :artist="$r['track']['artist']" :album="$r['track']['album']" size="size-6" />
                                        @endif
                                        <span class="font-medium">{{ $r['name'] }}</span>
                                        <span class="text-xs text-zinc-500">{{ $r['count'] }} {{ $r['count'] === 1 ? 'Datei' : 'Dateien' }}</span>
                                        <span class="text-xs {{ $r['mb'] ? 'text-emerald-300/80' : ($r['default'] ? 'text-zinc-400' : 'text-amber-300/80') }}">{{ $r['reason'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if ($wizSimilar)
                        <div class="text-sm">
                            <span class="text-zinc-400">Ähnlich im Verzeichnis – ist es dieselbe Person?</span>
                            <div class="mt-1.5 flex flex-wrap gap-2">
                                @foreach ($wizSimilar as $s)
                                    <button wire:click="wizAliasOf({{ $s->id }})" class="rounded-lg border border-violet-500/40 bg-violet-500/10 px-2.5 py-1 text-violet-100 hover:bg-violet-500/20" wire:key="ws{{ $s->id }}">
                                        Schreibweise von „{{ $s->name }}“{{ $s->disambiguation ? ' ('.$s->disambiguation.')' : '' }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>

                <div wire:loading.flex wire:target="wizLookup, wizSearchAgain" class="items-center gap-2 text-sm text-blue-300">
                    <span class="size-2 animate-pulse rounded-full bg-blue-400"></span> sucht bei MusicBrainz und Discogs und prüft eure Lieder …
                </div>

                @if ($wizLoaded)
                    @foreach ($wizErrors as $err)
                        <p class="text-sm text-amber-300">{{ $err }}</p>
                    @endforeach

                    <div class="grid grid-cols-2 gap-4">
                        {{-- 2. MusicBrainz --}}
                        <section class="card p-4">
                            <h3 class="mb-2 text-sm font-semibold text-zinc-300">2 · MusicBrainz</h3>
                            @forelse ($wizMb as $h)
                                <button wire:click="wizPickMb(@js($h['mbid']))" wire:key="wm{{ $h['mbid'] }}"
                                        class="mb-1 flex w-full items-start gap-2 rounded-lg border px-2.5 py-1.5 text-left text-sm {{ $wizMbid === $h['mbid'] ? 'border-blue-500 bg-blue-500/10' : 'border-zinc-800 hover:border-zinc-600' }}">
                                    <span class="mt-0.5 size-3.5 shrink-0 rounded-full border {{ $wizMbid === $h['mbid'] ? 'border-blue-400 bg-blue-500' : 'border-zinc-600' }}"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="font-medium">{{ $h['name'] }}</span>
                                        <span class="text-zinc-500">{{ $h['disambiguation'] }} {{ $h['country'] ? '['.$h['country'].']' : '' }}</span>
                                        @if ($h['songs'])
                                            <span class="block text-xs text-emerald-300">✓ hat „{{ $h['songs'][0] }}“ – passt zu euren Liedern</span>
                                        @elseif (! $h['exact'])
                                            <span class="block text-xs text-zinc-500">anderer Name</span>
                                        @endif
                                    </span>
                                    <span class="text-xs text-zinc-500">{{ $h['score'] }} %</span>
                                </button>
                            @empty
                                <p class="text-sm text-zinc-500">Kein Treffer.</p>
                            @endforelse
                        </section>

                        {{-- 3. Discogs --}}
                        <section class="card p-4">
                            <h3 class="mb-2 text-sm font-semibold text-zinc-300">3 · Discogs</h3>
                            @if (! app(\App\Support\Lookup::class)->hasDiscogs())
                                <p class="text-sm text-zinc-500">Kein Discogs-Token in den Einstellungen.</p>
                            @else
                                @forelse ($wizDiscogs as $d)
                                    <button wire:click="wizPickDiscogs(@js($d['discogs_id']))" wire:key="wd{{ $d['discogs_id'] }}"
                                            class="mb-1 flex w-full items-center gap-2 rounded-lg border px-2.5 py-1.5 text-left text-sm {{ $wizDiscogsId === $d['discogs_id'] ? 'border-blue-500 bg-blue-500/10' : 'border-zinc-800 hover:border-zinc-600' }}">
                                        <span class="size-3.5 shrink-0 rounded-full border {{ $wizDiscogsId === $d['discogs_id'] ? 'border-blue-400 bg-blue-500' : 'border-zinc-600' }}"></span>
                                        @if ($d['image'])<img src="{{ $d['image'] }}" class="size-7 rounded object-cover">@endif
                                        <span class="flex-1 truncate">{{ $d['raw'] }}</span>
                                    </button>
                                @empty
                                    <p class="text-sm text-zinc-500">Kein Treffer.</p>
                                @endforelse
                            @endif
                        </section>
                    </div>

                    {{-- 4. Bild --}}
                    <section class="card p-4">
                        <div class="mb-2 flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-zinc-300">4 · Bild</h3>
                            <button wire:click="wizFindImages" class="btn-ghost btn-sm" title="mit der gewählten MusicBrainz-/Discogs-ID neu suchen">
                                <span wire:loading.remove wire:target="wizFindImages">Neu suchen</span><span wire:loading wire:target="wizFindImages">sucht …</span>
                            </button>
                            <span class="text-xs text-zinc-500">Klick wählt aus – gespeichert wird mit „Speichern &amp; weiter“.</span>
                        </div>
                        @if ($wizImages)
                            <div class="grid grid-cols-6 gap-3">
                                @foreach ($wizImages as $i => $c)
                                    <button wire:click="wizPickImage({{ $i }})" class="text-left" wire:key="wi{{ md5($c['url']) }}">
                                        <img src="{{ $c['thumb'] }}" class="aspect-square w-full rounded-lg object-cover {{ $wizImage === $c['url'] ? 'ring-3 ring-blue-500' : 'ring-1 ring-zinc-700 hover:ring-zinc-500' }}">
                                        <span class="mt-1 block truncate text-xs text-zinc-400" title="{{ $c['source'] }} {{ $c['size'] }}">{{ $c['source'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">Keine Bilder gefunden. Eigenes Bild später im Verzeichnis hochladen.</p>
                        @endif
                    </section>

                    {{-- 5. Genre --}}
                    <section class="card space-y-2 p-4">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-zinc-300">5 · Standard-Genre</h3>
                            <select wire:model.live="wizGenre" class="input w-72">
                                <option value="">—</option>
                                @foreach ($genreOptions as $o)<option value="{{ $o['name'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                        </div>
                        @include('livewire.partials.genre-proposals', ['proposals' => $wizGenres, 'current' => $wizGenre, 'model' => 'wizGenre'])
                    </section>
                @endif
            </div>
        @endif
    </div>
</div>
