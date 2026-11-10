{{-- Schnell erfassen: offene Namen mit MusicBrainz-Treffer aus dem Vorladen, eindeutige gesammelt anlegen --}}
@php
    $pf = $prefetch;
    $selectable = in_array($quickKind, \App\Support\ArtistQuickMatch::SELECTABLE, true);
    $take = $takeStatus;
    $taken = $selectable ? collect($quickRows)->filter(fn ($r) => $quickTake[$r['key']] ?? false)->count() : 0;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-hidden" @if (($pf['running'] ?? false) || ($take['running'] ?? false)) wire:poll.5s @endif>
    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-zinc-800 px-6 py-3">
        <input type="search" wire:model.live.debounce.300ms="quickQ" placeholder="Namen suchen …" class="input-sm w-56">
        @foreach (\App\Support\ArtistQuickMatch::KINDS as $k => $label)
            <button wire:click="$set('quickKind', '{{ $k }}')" class="rounded-full border px-3 py-1 text-sm {{ $quickKind === $k ? 'border-blue-500 bg-blue-500/15 text-blue-100' : 'border-zinc-700 text-zinc-400 hover:border-zinc-500 hover:text-zinc-200' }}">
                {{ $label }} <span class="tabular-nums text-zinc-500">{{ $quickCounts[$k] ?? 0 }}</span>
            </button>
        @endforeach
        <div class="ml-auto text-xs text-zinc-500">
            @if ($spotifyUntil = \App\Support\Lookup::spotifyBlockedUntil())
                <span class="mr-3 text-amber-300" title="Zu viele Abfragen – die App fragt Spotify bis dahin gar nicht und holt die betroffenen Namen danach beim nächsten Nachschlagen nach">Spotify bremst – wieder ab {{ $spotifyUntil->format('H:i') }} Uhr</span>
            @endif
            @if ($pf['running'] ?? false)
                @php $left = max(0, ($pf['total'] ?? 0) - ($pf['done'] ?? 0)); @endphp
                <span class="text-violet-300" title="MusicBrainz, Deezer, Spotify … über eure Lieder">Vorladen läuft: {{ $pf['done'] ?? 0 }} / {{ $pf['total'] ?? 0 }} · noch ca. {{ max(1, (int) ceil($left * 2 / 60)) }} Min.</span>
            @elseif (($quickCounts['pending'] ?? 0) || $quickUnchecked)
                <button wire:click="startPrefetch" class="text-zinc-400 underline hover:text-white"
                        title="Unter welchem Künstler führen Deezer und Spotify eure Lieder? Bestätigt Namen (Standard), findet Tippfehler und das richtige Bild. Läuft im Hintergrund.">
                    {{ ($quickCounts['pending'] ?? 0) + $quickUnchecked }} Namen jetzt nachschlagen{{ $quickUnchecked ? ' (auch bei Deezer und Spotify)' : '' }}
                </button>
            @endif
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
        <p class="mb-3 max-w-4xl text-sm text-zinc-400">
            @switch($quickKind)
                @case('standard') MusicBrainz <strong>und</strong> Deezer bzw. Spotify finden eure Lieder unter genau diesem Namen – unstrittig. Übernommen mit MusicBrainz-ID, Standard-Genre und Schreibweisen; Bild nur, wenn es sicher der richtige Künstler ist (über die MusicBrainz-ID oder eines eurer Lieder), sonst ohne – die findest du danach im Verzeichnis unter „ohne Bild“. Ein anderes Bild (oder keins) wählst du per Klick in der Zeile. @break
                @case('sure') Genau ein MusicBrainz-Künstler hat eure Lieder und heißt so – Deezer und Spotify bestätigen es (noch) nicht. Bild anklicken hakt die Zeile an – ohne Bild von Hand anhaken. Angelegt wird mit MusicBrainz-ID, Standard-Genre und den Schreibweisen von MusicBrainz. @break
                @case('service') Deezer bzw. Spotify führen eure Lieder unter genau diesem Namen, MusicBrainz hilft nicht weiter (nicht gefunden oder mehrere gleichnamige). Angelegt wird ohne MusicBrainz-ID (außer es gibt dort genau einen gleichnamigen) – Bild anklicken hakt an. @break
                @case('typo') Ein Dienst führt eure Lieder unter einem sehr ähnlichen Namen – wohl ein Tippfehler im Bestand. „Schreibweise von …“ legt den richtigen Namen an (bzw. nimmt den vorhandenen) und merkt die Tags vor. @break
                @case('name') Gleicher Name bei MusicBrainz, aber eure Lieder wurden dort nicht gefunden – bitte nur anhaken, wenn du dir sicher bist. @break
                @case('unsure') Braucht eine Entscheidung (mehrere Treffer, ähnliche Namen, Kombi) – einzeln im Assistenten. @break
                @default Noch ohne MusicBrainz-Ergebnis – das Vorladen läuft nach jedem Scan von selbst (etwa eine Abfrage pro Sekunde).
            @endswitch
        </p>

        @if ($quickKind === 'standard')
            <div class="mb-4 flex max-w-6xl flex-wrap items-center gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm">
                @if ($take['running'] ?? false)
                    <div class="min-w-64 flex-1">
                        <div class="mb-1.5 text-emerald-200">Übernehme im Hintergrund: {{ $take['done'] ?? 0 }} / {{ $take['total'] ?? 0 }} · {{ $take['created'] ?? 0 }} angelegt · {{ $take['images'] ?? 0 }} mit Bild</div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-800"><div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ ($take['total'] ?? 0) ? round(100 * ($take['done'] ?? 0) / $take['total']) : 0 }}%"></div></div>
                    </div>
                @else
                    <span class="flex-1 text-emerald-100">
                        {{ $quickCounts['standard'] ?? 0 }} unstrittige Künstler
                        @if (! empty($take['finished_at']))<span class="text-xs text-zinc-500"> · zuletzt {{ $take['created'] ?? 0 }} angelegt, {{ $take['images'] ?? 0 }} mit Bild ({{ \Illuminate\Support\Carbon::parse($take['finished_at'])->diffForHumans() }})</span>@endif
                    </span>
                    @if ($quickCounts['standard'] ?? 0)
                        <button wire:click="takeStandard" data-confirm-label="Übernehmen"
                                wire:confirm="{{ $quickCounts['standard'] }} Künstler ins Verzeichnis übernehmen? Mit MusicBrainz-ID, Genre, Schreibweisen und – wo sicher – Bild. Läuft im Hintergrund, Tags werden nur vorgemerkt."
                                class="btn-primary">Alle {{ $quickCounts['standard'] }} übernehmen</button>
                    @endif
                @endif
            </div>
        @endif

        @if ($quickRows)
            <table class="w-full max-w-6xl text-sm">
                <thead class="text-left text-xs text-zinc-500">
                    <tr>
                        @if ($selectable)<th class="w-8 pb-2"></th>@endif
                        <th class="pb-2">Name im Bestand</th>
                        <th class="pb-2">MusicBrainz</th>
                        <th class="pb-2">Begründung</th>
                        @if ($selectable)<th class="pb-2">Bild</th>@endif
                        @if ($quickKind === 'standard')<th class="pb-2">Bild (grün = wird übernommen)</th>@endif
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($quickRows as $r)
                        @php $h = $r['hit']; @endphp
                        <tr class="border-t border-zinc-800/70 align-top" wire:key="q{{ $r['key'] }}">
                            @if ($selectable)
                                <td class="py-2"><input type="checkbox" wire:model.live="quickTake.{{ $r['key'] }}" class="rounded border-zinc-600 bg-zinc-800"></td>
                            @endif
                            <td class="py-2 pr-3">
                                <span class="text-zinc-100">{{ $r['name'] }}</span>
                                <span class="text-xs text-zinc-500">{{ $r['count'] }} Titel</span>
                            </td>
                            <td class="py-2 pr-3">
                                @if ($h)
                                    <a href="https://musicbrainz.org/artist/{{ $h['mbid'] }}" target="_blank" rel="noopener" class="text-zinc-200 hover:underline">{{ $h['name'] }}</a>
                                    <span class="text-xs text-zinc-500">{{ collect([$h['disambiguation'] ?? null, $h['type'] ?? null, $h['country'] ?? null])->filter()->implode(' · ') }}</span>
                                    @if ($selectable && $h['name'] !== $r['name'])
                                        <label class="mt-0.5 flex items-center gap-1.5 text-xs text-blue-300">
                                            <input type="checkbox" wire:model.live="quickMb.{{ $r['key'] }}" class="rounded border-zinc-600 bg-zinc-800">
                                            Schreibweise „{{ $h['name'] }}“ übernehmen <span class="text-zinc-500">(„{{ $r['name'] }}“ bleibt Schreibweise, Tags werden vorgemerkt)</span>
                                        </label>
                                    @endif
                                @else
                                    <span class="text-zinc-600">–</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-xs {{ in_array($r['kind'], ['standard', 'sure', 'service'], true) ? 'text-emerald-300' : 'text-zinc-400' }}">
                                {{ $r['reason'] }}
                                @if ($r['combi'] ?? null)
                                    <x-combi-verdict :combi="$r['combi']" class="mt-0.5" />
                                @endif
                            </td>
                            @if ($quickKind === 'standard')
                                @php
                                    $chosen = array_key_exists($r['key'], $quickChoice);
                                    // übernommen wird: die eigene Wahl ('' = ohne Bild), sonst das sichere Bild
                                    $useUrl = $chosen ? $quickChoice[$r['key']] : ($quickSafe[$r['key']]['url'] ?? '');
                                @endphp
                                <td class="py-2 pr-3">
                                    <div class="flex items-center gap-1.5">
                                        @forelse ($quickImages[$r['key']] ?? [] as $img)
                                            @php $picked = $useUrl === $img['url']; @endphp
                                            <button wire:click="quickStandardImage(@js($r['key']), @js($img['url']), {{ $picked ? 'true' : 'false' }})"
                                                    title="{{ $img['source'] }}{{ $img['size'] ? ' · '.$img['size'] : '' }}{{ $picked ? ' – wird übernommen (nochmal klicken: ohne Bild)' : ' – stattdessen dieses Bild nehmen' }}"
                                                    class="relative size-14 shrink-0 overflow-hidden rounded-md ring-2 transition {{ $picked ? 'ring-emerald-500' : 'ring-transparent opacity-60 hover:opacity-100 hover:ring-zinc-500' }}">
                                                <img src="{{ $img['thumb'] }}" alt="" loading="lazy" class="size-full object-cover">
                                                @if ($picked)<span class="absolute right-0.5 bottom-0.5 rounded bg-emerald-500 px-1 text-[10px] text-white">✓</span>@endif
                                            </button>
                                        @empty
                                            <span class="text-xs text-zinc-600">keine Vorschläge</span>
                                        @endforelse
                                        <div class="flex flex-col items-start gap-0.5 text-xs">
                                            @if ($useUrl === '' && ($quickImages[$r['key']] ?? []))<span class="text-zinc-500">ohne Bild</span>@endif
                                            @if ($chosen)
                                                <button wire:click="quickStandardImage(@js($r['key']), null)" class="text-zinc-500 underline hover:text-white" title="Wieder das vorgeschlagene (sichere) Bild bzw. ohne Bild, wenn es keins gibt">↺ Vorschlag</button>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            @endif
                            @if ($selectable)
                                <td class="py-2 pr-3">
                                    <div class="flex gap-1.5">
                                        @forelse ($quickImages[$r['key']] ?? [] as $img)
                                            @php $picked = ($quickImage[$r['key']] ?? null) === $img['url']; @endphp
                                            <button wire:click="quickPickImage(@js($r['key']), @js($img['url']))" title="{{ $img['source'] }}{{ $img['size'] ? ' · '.$img['size'] : '' }}{{ $picked ? ' – gewählt (nochmal klicken: abwählen)' : ' – als Bild nehmen' }}"
                                                    class="relative size-14 shrink-0 overflow-hidden rounded-md ring-2 transition {{ $picked ? 'ring-blue-500' : 'ring-transparent opacity-80 hover:opacity-100 hover:ring-zinc-500' }}">
                                                <img src="{{ $img['thumb'] }}" alt="" loading="lazy" class="size-full object-cover">
                                                @if ($picked)<span class="absolute right-0.5 bottom-0.5 rounded bg-blue-500 px-1 text-[10px] text-white">✓</span>@endif
                                            </button>
                                        @empty
                                            <span class="text-xs text-zinc-600">keine Vorschläge</span>
                                        @endforelse
                                    </div>
                                </td>
                            @endif
                            <td class="space-x-3 py-2 text-right whitespace-nowrap">
                                @if ($r['kind'] === 'typo' && $r['suggest'])
                                    <button wire:click="quickTypo(@js($r['key']))" class="btn-ghost btn-sm" title="„{{ $r['name'] }}“ wird Schreibweise von „{{ $r['suggest'] }}“ (legt ihn an, falls nötig) – Tags werden vorgemerkt">Schreibweise von „{{ $r['suggest'] }}“</button>
                                @endif
                                @if ($r['split'] ?? [])
                                    <button wire:click="quickSplit(@js($r['name']))" class="btn-ghost btn-sm" title="Zusammenarbeit – künftig überall in die einzelnen Künstler trennen (ARTISTS), Tags werden vorgemerkt">Trennen: {{ implode(' · ', $r['split']) }}</button>
                                @endif
                                <button wire:click="$dispatch('open-new-artist', { name: @js($r['name']) })" class="text-xs text-zinc-500 hover:text-white"
                                        title="Einzeln erfassen – Treffer, Bild, Genre und Schreibweisen von Hand (Dialog, die Liste bleibt)">einzeln erfassen …</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($quickTotal > count($quickRows))
                <button wire:click="$set('quickLimit', {{ $quickLimit + 100 }})" class="btn-ghost mt-3">Mehr anzeigen (noch {{ $quickTotal - count($quickRows) }})</button>
            @endif
        @else
            <p class="py-10 text-center text-zinc-500">{{ $quickQ !== '' ? 'Kein Name passt zur Suche.' : 'Keine Namen in dieser Gruppe.' }}</p>
        @endif
    </div>

    @if ($selectable && $quickRows)
        <footer class="flex shrink-0 items-center gap-3 border-t border-zinc-800 px-6 py-3">
            <button wire:click="$set('quickTake', @js(collect($quickRows)->mapWithKeys(fn ($r) => [$r['key'] => true])->all()))" class="text-xs text-zinc-400 hover:text-white">alle anhaken</button>
            <button wire:click="$set('quickTake', @js(collect($quickRows)->mapWithKeys(fn ($r) => [$r['key'] => false])->all()))" class="text-xs text-zinc-400 hover:text-white">keine</button>
            <button wire:click="quickSave" data-confirm-label="Anlegen"
                    wire:confirm="{{ $taken }} Künstler ins Verzeichnis übernehmen? Mit MusicBrainz-ID (wo vorhanden), Standard-Genre und Schreibweisen."
                    class="btn-primary ml-auto" @disabled(! $taken)>
                <span wire:loading.remove wire:target="quickSave">{{ $taken }} Künstler anlegen</span><span wire:loading wire:target="quickSave">legt an …</span>
            </button>
        </footer>
    @endif
</div>
