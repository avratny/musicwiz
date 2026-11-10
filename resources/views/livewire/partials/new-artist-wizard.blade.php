{{-- Neue Künstler erfassen – Aufbau wie das Verzeichnis: Warteschlange links, rechts fester Kopf und Kästen --}}
@php
    $card = 'rounded-xl border border-zinc-800 bg-zinc-900/40 p-4';
    $title = 'mb-2 text-xs font-medium tracking-wide text-zinc-400 uppercase';
    $bar = '<div class="progress-indeterminate h-1.5 overflow-hidden rounded-full bg-zinc-800"><div class="h-full w-1/3 rounded-full bg-gradient-to-r from-blue-500 to-violet-500"></div></div>';
    $pickedThumb = $wizImage ? (collect($wizImages)->firstWhere('url', $wizImage)['thumb'] ?? $wizImage) : null;
@endphp
<div class="flex min-h-0 flex-1 overflow-hidden">
    {{-- Warteschlange (nicht im Einzelmodus = Dialog im Editor) --}}
    @unless ($wizSingle)
        <div class="flex w-80 shrink-0 flex-col border-r border-zinc-800">
            <div class="space-y-2 border-b border-zinc-800 p-3">
                <input type="search" wire:model.live.debounce.300ms="wizQ" placeholder="Namen suchen …" class="input-sm w-full">
                <div class="text-xs text-zinc-400"><span class="font-semibold text-zinc-200">{{ count($wizQueue) }}</span> Namen noch nicht im Verzeichnis{{ $wizSkipped ? ' · '.count($wizSkipped).' übersprungen' : '' }}</div>
                {{-- Vorladen im Hintergrund: Treffer und Genres liegen dann schon bereit --}}
                @php $pf = $prefetch ?? []; @endphp
                <div class="text-xs" @if ($pf['running'] ?? false) wire:poll.5s @endif>
                    @if ($pf['running'] ?? false)
                        @php $left = max(0, ($pf['total'] ?? 0) - ($pf['done'] ?? 0)); @endphp
                        <div class="flex items-center gap-2 text-blue-300" title="Inbox-Künstler zuerst (für „… erfassen“ im Editor), dann die Lib. Diese Liste zeigt nur Künstler aus der Lib.">
                            <span class="size-2 animate-pulse rounded-full bg-blue-400"></span>
                            Vorladen: {{ $pf['done'] ?? 0 }} von {{ $pf['total'] ?? 0 }} · noch ca. {{ max(1, (int) ceil($left * 4 / 60)) }} Min.
                        </div>
                        @isset($pf['inbox'])<div class="mt-0.5 text-zinc-500">{{ $pf['inbox'] }} aus der Inbox, {{ $pf['library'] }} aus der Lib</div>@endisset
                        @if (! empty($pf['current']))<div class="mt-0.5 truncate text-zinc-500" title="{{ $pf['current'] }}">gerade: {{ $pf['current'] }}</div>@endif
                    @else
                        <button wire:click="startPrefetch" class="text-zinc-500 hover:text-white" title="Alle unbekannten Künstler aus Inbox und Lib im Hintergrund nachschlagen – läuft sonst nach jedem Scan von selbst">↓ Alle im Hintergrund vorladen</button>
                        @if (! empty($pf['finished_at']))<span class="text-zinc-600"> · zuletzt {{ \Illuminate\Support\Carbon::parse($pf['finished_at'])->diffForHumans() }}</span>@endif
                    @endif
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-2">
                @forelse ($wizQueueShown as $qName => $qCount)
                    <button wire:click="wizOpen(@js($qName))" wire:key="wq{{ md5($qName) }}"
                            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition {{ $qName === $wizName ? 'bg-blue-600/20 text-blue-100 ring-1 ring-blue-500/40' : 'text-zinc-300 hover:bg-zinc-800' }}">
                        <span class="flex-1 truncate" title="{{ $qName }}">{{ $qName }}</span>
                        <span class="text-xs text-zinc-500">{{ $qCount }}</span>
                    </button>
                @empty
                    <p class="p-3 text-sm text-zinc-500">{{ $wizQ !== '' ? 'Kein Name passt zur Suche.' : 'Leer.' }}</p>
                @endforelse
            </div>
        </div>
    @endunless

    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
        @if ($wizName === null)
            <div class="grid flex-1 place-items-center text-center text-zinc-400">
                <div>
                    <p class="text-lg text-zinc-300">Alle Künstler aus dem Bestand stehen im Verzeichnis.</p>
                    @if ($wizSkipped)<p class="mt-1 text-sm">{{ count($wizSkipped) }} übersprungen – sie kommen beim nächsten Öffnen der Seite wieder.</p>@endif
                </div>
            </div>
        @else
            {{-- wire:key pro Name: beim Wechsel startet x-init das Nachschlagen für den neuen Namen --}}
            <div class="flex min-h-0 flex-1 flex-col" wire:key="wiz{{ md5($wizName) }}" x-data x-init="$wire.wizLookup()">
                {{-- Kopf: fest oben über die volle Breite --}}
                <div class="shrink-0 border-b border-zinc-800 bg-zinc-950 px-6 py-4">
                    <div class="flex max-w-5xl items-start gap-4">
                        <button x-on:click="$wire.wizImagesOpen = true; @if (! $wizImages) $wire.wizFindImages() @endif" class="group relative size-20 shrink-0" title="Bild wählen">
                            @if ($pickedThumb)
                                <img src="{{ $pickedThumb }}" class="size-20 rounded-2xl object-cover ring-2 ring-blue-500">
                            @else
                                <span class="grid size-20 place-items-center rounded-2xl bg-gradient-to-br from-blue-600/30 to-violet-600/30 text-3xl font-semibold">{{ mb_substr($wizFinal ?: $wizName, 0, 1) }}</span>
                            @endif
                            <span class="absolute inset-0 grid place-items-center rounded-2xl bg-black/60 text-xs text-white opacity-0 transition group-hover:opacity-100">Bild …</span>
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="px-1 text-xs tracking-wide text-zinc-500 uppercase">Neuer Künstler · {{ $wizCount }} {{ $wizSingle ? 'Titel in diesem Album' : ($wizCount === 1 ? 'Datei' : 'Dateien').' im Bestand' }}</div>
                            <div class="flex items-center gap-2">
                                <input wire:ref="wizFinal" wire:model.blur="wizFinal" wire:keydown.enter="wizSearchAgain($event.target.value)"
                                       class="min-w-0 flex-1 rounded-lg border border-transparent bg-transparent px-1 text-2xl font-semibold text-zinc-100 hover:border-zinc-700 focus:border-zinc-600 focus:bg-zinc-900 focus:outline-none" title="Schreibweise, unter der gespeichert wird – Enter sucht damit">
                                <button wire:click="wizSearchAgain($refs.wizFinal.value)" class="btn-ghost btn-sm shrink-0" title="bei MusicBrainz und Discogs mit dieser Schreibweise suchen (oder Enter im Feld)">
                                    <span wire:loading.remove wire:target="wizSearchAgain">Suchen</span><span wire:loading wire:target="wizSearchAgain">sucht …</span>
                                </button>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-xs">
                                @if ($wizSearched !== $wizName)<span class="text-zinc-500">gesucht mit „{{ $wizSearched }}“</span>@endif
                                @if ($wizFinal !== $wizName)
                                    <span class="text-zinc-400">„{{ $wizName }}“ wird als Schreibweise gespeichert</span>
                                    <button wire:click="$set('wizFinal', @js($wizName))" class="text-blue-400 hover:underline">zurücksetzen</button>
                                @endif
                                @if ($wizMbName && \App\Support\ArtistGroupAnalyzer::cleanMbName($wizMbName) !== $wizFinal)
                                    <button wire:click="$set('wizFinal', @js(\App\Support\ArtistGroupAnalyzer::cleanMbName($wizMbName)))" class="badge-blue hover:bg-blue-500/30">MusicBrainz schreibt „{{ \App\Support\ArtistGroupAnalyzer::cleanMbName($wizMbName) }}“ – übernehmen</button>
                                @endif
                                @foreach ($wizGenreList as $g)<span class="badge-violet">{{ $g }}</span>@endforeach
                                @if ($wizLoaded)
                                    <button wire:click="wizRefresh" class="text-zinc-500 hover:text-white" title="Ohne Zwischenspeicher neu bei MusicBrainz, Discogs und den Bilderdiensten fragen (Ergebnisse werden sonst {{ config('musicwiz.lookup_cache_days') }} Tage gemerkt)">
                                        <span wire:loading.remove wire:target="wizRefresh">↻ Aktuelle Daten laden</span><span wire:loading wire:target="wizRefresh">lädt aktuelle Daten …</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($wizSingle)
                                <button wire:click="close" class="btn-ghost">Abbrechen</button>
                                <button wire:click="wizSave" wire:loading.attr="disabled" class="btn-primary">Speichern</button>
                            @else
                                <button wire:click="wizSkip" class="btn-ghost">Überspringen</button>
                                <button wire:click="wizSave" wire:loading.attr="disabled" class="btn-primary">Speichern &amp; weiter</button>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-6">
                    <div class="grid max-w-5xl gap-4 lg:grid-cols-2">
                        @if ($wizCombined)
                            <div class="rounded-xl border {{ ($wizCombi['verdict'] ?? null) === 'act' ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-100' : 'border-amber-500/30 bg-amber-500/10 text-amber-200' }} px-4 py-3 text-sm lg:col-span-2">
                                @if (($wizCombi['verdict'] ?? null) === 'act')
                                    Laut Deezer, Spotify bzw. MusicBrainz ein Act (Duo/Band) – speichern passt.
                                @elseif (($wizCombi['verdict'] ?? null) === 'collab')
                                    Laut Deezer, Spotify bzw. MusicBrainz eine <strong>Zusammenarbeit</strong> von {{ implode(' + ', $wizCombi['parts']) }} – besser nicht als ein Künstler speichern, sondern trennen (Aufräumen › Kombi bzw. Künstler › Schnell erfassen).
                                @else
                                    Sieht nach mehreren Künstlern aus („&“, „und“, „,“ …). Getrennt wird nie automatisch – ist es ein Duo bzw. eine Band, einfach speichern; sonst überspringen und unter „Aufräumen › Kombi“ entscheiden.
                                @endif
                                @if ($wizCombi)<x-combi-verdict :combi="$wizCombi" class="mt-1" />@endif
                            </div>
                        @endif

                        {{-- Zuordnung: MusicBrainz und Discogs --}}
                        <section class="{{ $card }}">
                            <h3 class="{{ $title }}">Zuordnung</h3>
                            <div wire:loading.block wire:target="wizLookup, wizSearchAgain, wizRefresh" class="mb-3">
                                <div class="mb-1.5 text-xs text-blue-300">sucht bei MusicBrainz und Discogs und prüft eure Lieder … (MusicBrainz: etwa eine Abfrage pro Sekunde)</div>
                                {!! $bar !!}
                            </div>
                            @foreach ($wizErrors as $err)<p class="mb-2 text-xs text-amber-300">{{ $err }}</p>@endforeach
                            @if ($wizLoaded)
                                <div class="text-xs text-zinc-400">MusicBrainz</div>
                                <div class="mt-1 space-y-1">
                                    @forelse ($wizMb as $h)
                                        <button wire:click="wizPickMb(@js($h['mbid']))" wire:key="wm{{ $h['mbid'] }}"
                                                class="flex w-full items-start gap-2 rounded-lg border px-2.5 py-1.5 text-left text-sm {{ $wizMbid === $h['mbid'] ? 'border-blue-500 bg-blue-500/10' : 'border-zinc-800 hover:border-zinc-600' }}">
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
                                </div>
                                <div class="mt-3 text-xs text-zinc-400">Discogs</div>
                                <div class="mt-1 space-y-1">
                                    @if (! app(\App\Support\Lookup::class)->hasDiscogs())
                                        <p class="text-sm text-zinc-500">Kein Discogs-Token in den Einstellungen.</p>
                                    @else
                                        @forelse ($wizDiscogs as $d)
                                            <button wire:click="wizPickDiscogs(@js($d['discogs_id']))" wire:key="wd{{ $d['discogs_id'] }}"
                                                    class="flex w-full items-center gap-2 rounded-lg border px-2.5 py-1.5 text-left text-sm {{ $wizDiscogsId === $d['discogs_id'] ? 'border-blue-500 bg-blue-500/10' : 'border-zinc-800 hover:border-zinc-600' }}">
                                                <span class="size-3.5 shrink-0 rounded-full border {{ $wizDiscogsId === $d['discogs_id'] ? 'border-blue-400 bg-blue-500' : 'border-zinc-600' }}"></span>
                                                @if ($d['image'])<img src="{{ $d['image'] }}" class="size-7 rounded object-cover">@endif
                                                <span class="flex-1 truncate">{{ $d['raw'] }}</span>
                                            </button>
                                        @empty
                                            <p class="text-sm text-zinc-500">Kein Treffer.</p>
                                        @endforelse
                                    @endif
                                </div>
                            @endif
                        </section>

                        {{-- Standard-Genres --}}
                        <section class="{{ $card }}">
                            <h3 class="{{ $title }}">Standard-Genres <span class="normal-case tracking-normal text-zinc-500">· bis zu {{ \App\Models\Artist::MAX_GENRES }}</span></h3>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @forelse ($wizGenreList as $g)
                                    <span class="badge-violet gap-1">{{ $g }}<button wire:click="wizRemoveGenre(@js($g))" class="opacity-60 hover:opacity-100" title="entfernen">✕</button></span>
                                @empty
                                    <span class="text-xs text-zinc-500">noch keins</span>
                                @endforelse
                                <select x-data x-on:change="$wire.wizAddGenre($event.target.value); $event.target.value = ''" class="input-sm w-auto py-0.5 text-xs">
                                    <option value="">+ Genre …</option>
                                    @foreach ($genreOptions as $o)<option value="{{ $o['name'] }}" @disabled(in_array($o['name'], $wizGenreList, true))>{{ $o['label'] }}</option>@endforeach
                                </select>
                            </div>
                            @if ($wizLoaded && ! $wizDetailsLoaded)
                                {{-- zweiter Schritt: Treffer sind da, Genres und Schreibweisen kommen nach (Bilder erst im Bilddialog) --}}
                                <div wire:key="wizd{{ md5($wizName.'|'.$wizSearched) }}" x-data x-init="$wire.wizLookupDetails()" class="mt-3">
                                    <div class="mb-1.5 text-xs text-blue-300">lädt Genres und Schreibweisen …</div>
                                    {!! $bar !!}
                                </div>
                            @elseif ($wizGenres)
                                <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                                    @foreach ($wizGenres as $p)
                                        @foreach (array_filter([$p['genre'] ?? null, ...($p['tied'] ?? [])]) as $g)
                                            @php $on = in_array($g, $wizGenreList, true); @endphp
                                            <button wire:click="{{ $on ? 'wizRemoveGenre' : 'wizAddGenre' }}(@js($g))" title="{{ $p['detail'] }}"
                                                    class="rounded-lg border px-2.5 py-1 text-left {{ $on ? 'border-blue-500 bg-blue-500/10 text-blue-100' : 'border-zinc-700 text-zinc-300 hover:border-zinc-500' }}">
                                                <span class="text-zinc-500">{{ $p['source'] }}:</span> <span class="font-medium">{{ $g }}</span>{{ $on ? ' ✓' : '' }}
                                            </button>
                                        @endforeach
                                        @if (! ($p['genre'] ?? null) && empty($p['tied']))
                                            <span class="rounded-lg border border-zinc-800 px-2.5 py-1 text-zinc-500" title="{{ $p['detail'] }}">{{ $p['source'] }}: {{ \Illuminate\Support\Str::limit($p['detail'], 50) }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        {{-- Schreibweisen und ähnliche Namen --}}
                        <section class="{{ $card }}">
                            <h3 class="{{ $title }}">Schreibweisen</h3>
                            @if ($wizRelated)
                                <p class="text-xs text-zinc-400">Ähnliche Namen im Bestand, noch nicht erfasst – angehakte werden als Schreibweisen mitgespeichert:</p>
                                <div class="mt-1.5 space-y-1">
                                    @foreach ($wizRelated as $r)
                                        <label class="flex items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-zinc-800/60" wire:key="wr{{ $r['key'] }}">
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
                            @else
                                <p class="text-xs text-zinc-500">Keine ähnlichen Namen im Bestand.</p>
                            @endif
                            @if ($wizSimilar)
                                <p class="mt-3 text-xs text-zinc-400">Ähnlich im Verzeichnis – ist es dieselbe Person?</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    @foreach ($wizSimilar as $s)
                                        <button wire:click="wizAliasOf({{ $s->id }})" class="rounded-lg border border-violet-500/40 bg-violet-500/10 px-2.5 py-1 text-sm text-violet-100 hover:bg-violet-500/20" wire:key="ws{{ $s->id }}">
                                            Schreibweise von „{{ $s->name }}“{{ $s->disambiguation ? ' ('.$s->disambiguation.')' : '' }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        {{-- Hörproben --}}
                        <section class="{{ $card }}">
                            <h3 class="{{ $title }}">Hörproben</h3>
                            @if ($wizTracks)
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($wizTracks as $t)
                                        <div class="flex items-center gap-2 rounded-lg border border-zinc-800 px-2 py-1 text-xs" wire:key="wt{{ $t['id'] }}">
                                            <x-play-button :id="$t['id']" :seconds="$t['seconds']" :title="$t['title']" :artist="$t['artist']" :album="$t['album']" size="size-6" />
                                            <span class="max-w-48 truncate" title="{{ $t['title'] }} – {{ $t['album'] }}">{{ $t['title'] }} <span class="text-zinc-500">· {{ $t['album'] }}</span></span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-zinc-500">Keine Lieder gefunden.</p>
                            @endif
                        </section>
                    </div>
                </div>

                {{-- Bildauswahl: öffnet sofort, Suche mit Wartebalken; Klick wählt und schließt (gespeichert wird mit „Speichern“) --}}
                <div x-data="{ open: $wire.entangle('wizImagesOpen') }" x-show="open" x-cloak x-transition.opacity
                     class="fixed inset-0 z-[60] grid place-items-center bg-black/70 p-6 backdrop-blur-sm" x-on:keydown.escape.window="open = false" x-on:click.self="open = false">
                    <div class="w-full max-w-4xl rounded-2xl border border-zinc-700 bg-zinc-900 p-5 shadow-2xl">
                        <div class="flex items-center gap-3">
                            <h3 class="flex-1 text-lg font-semibold">Bild für „{{ $wizFinal ?: $wizName }}“</h3>
                            <button x-on:click="open = false" class="text-zinc-400 hover:text-white">✕</button>
                        </div>
                        <p class="mt-1 text-sm text-zinc-400">Klick wählt das Bild – gespeichert wird mit „{{ $wizSingle ? 'Speichern' : 'Speichern & weiter' }}“. Quellen: TheAudioDB, Spotify, Deezer, Fanart.tv, Discogs (mit gewählter MusicBrainz- bzw. Discogs-ID).</p>
                        <div wire:loading.block wire:target="wizFindImages" class="mt-5">
                            <div class="mb-2 text-sm text-violet-300">sucht Bilder bei den Diensten …</div>
                            {!! $bar !!}
                        </div>
                        <div wire:loading.remove wire:target="wizFindImages">
                            @if ($wizImages)
                                <div class="mt-4 grid max-h-[60vh] grid-cols-3 gap-3 overflow-y-auto sm:grid-cols-4 lg:grid-cols-5">
                                    @foreach ($wizImages as $i => $c)
                                        <button wire:click="wizPickImage({{ $i }})" class="text-left" wire:key="wi{{ md5($c['url']) }}">
                                            <img src="{{ $c['thumb'] }}" loading="lazy" class="aspect-square w-full rounded-lg object-cover {{ $wizImage === $c['url'] ? 'ring-3 ring-blue-500' : 'ring-1 ring-zinc-700 hover:ring-zinc-500' }}">
                                            <span class="mt-1 block truncate text-xs text-zinc-400" title="{{ $c['source'] }} {{ $c['size'] }}">{{ $c['source'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-4 text-sm text-zinc-500">Keine Bilder gefunden – eigenes Bild per URL laden oder später im Verzeichnis hochladen.</p>
                            @endif
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-800 pt-4">
                            <input type="url" wire:model="wizImageUrl" wire:keydown.enter="wizLoadImageUrl" placeholder="oder Bild-URL einfügen (https://…)" class="input-sm w-80 text-xs">
                            <button wire:click="wizLoadImageUrl" class="btn-ghost btn-sm"><span wire:loading.remove wire:target="wizLoadImageUrl">Laden</span><span wire:loading wire:target="wizLoadImageUrl">lädt …</span></button>
                            <button wire:click="wizFindImages" class="btn-ghost btn-sm">Neu suchen</button>
                            @if ($wizImage)<button wire:click="$set('wizImage', null)" class="btn-ghost btn-sm ml-auto">kein Bild</button>@endif
                            @error('wizImageUrl')<span class="text-xs text-red-300">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
