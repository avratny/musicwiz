{{-- Verzeichnis, rechte Seite: gewählter Künstler auf einer Seite – Kopf mit „Speichern“, darunter Kästen --}}
@php
    $isCombo = \App\Support\CombiCheck::applies($artist->name);
    $kind = ($combi['verdict'] ?? null) === 'act' ? 'Act' : (($audiodb['members'] ?? null) ? (($audiodb['members'] === 1) ? 'Solo' : 'Gruppe ('.$audiodb['members'].')') : null);
    $selectedGenres = (array) ($form['genres'] ?? []);
    $card = 'rounded-xl border border-zinc-800 bg-zinc-900/40 p-4';
    $title = 'mb-2 text-xs font-medium tracking-wide text-zinc-400 uppercase';
@endphp
{{-- Kopf: fest oben über die volle Breite, nur der Bereich darunter scrollt --}}
<div class="shrink-0 border-b border-zinc-800 bg-zinc-950 px-6 py-4">
        <div class="flex max-w-5xl items-start gap-4">
            <button x-on:click="$wire.imagesOpen = true; $wire.findImages()" class="group relative size-20 shrink-0" title="Bild wählen">
                @if ($artist->imageUrl())
                    <img src="{{ $artist->imageUrl() }}" class="size-20 rounded-2xl object-cover ring-1 ring-zinc-700">
                @else
                    <span class="grid size-20 place-items-center rounded-2xl bg-gradient-to-br from-blue-600/30 to-violet-600/30 text-3xl font-semibold">{{ mb_substr($artist->name, 0, 1) }}</span>
                @endif
                <span class="absolute inset-0 grid place-items-center rounded-2xl bg-black/60 text-xs text-white opacity-0 transition group-hover:opacity-100">Bild …</span>
            </button>
            <div class="min-w-0 flex-1">
                <input wire:model.live.debounce.400ms="form.name" class="w-full rounded-lg border border-transparent bg-transparent px-1 text-2xl font-semibold text-zinc-100 hover:border-zinc-700 focus:border-zinc-600 focus:bg-zinc-900 focus:outline-none" title="Name – ändern und speichern benennt um (alter Name wird Schreibweise)">
                @error('form.name')<p class="px-1 text-sm text-red-400">{{ $message }}</p>@enderror
                <div class="mt-1 flex flex-wrap items-center gap-1.5 px-1 text-xs">
                    @if ($kind)<span class="badge-gray">{{ $kind }}</span>@endif
                    @foreach ($artist->genres() as $g)<span class="badge-violet">{{ $g }}</span>@endforeach
                    @if ($audiodb['country'] ?? null)<span class="badge-gray">{{ $audiodb['country'] }}</span>@endif
                    @if ($artist->base_name)<span class="badge-amber" title="Gleichnamige Künstler – Zuordnung unten">gleichnamig</span>@endif
                    @if ($artist->mbid)<a href="https://musicbrainz.org/artist/{{ $artist->mbid }}" target="_blank" rel="noopener" class="badge-gray hover:text-white">MusicBrainz ↗</a>@endif
                    @if ($artist->discogs_id)<a href="https://www.discogs.com/artist/{{ $artist->discogs_id }}" target="_blank" rel="noopener" class="badge-gray hover:text-white">Discogs ↗</a>@endif
                    @if ($spotifyId ?? null)<a href="https://open.spotify.com/artist/{{ $spotifyId }}" target="_blank" rel="noopener" class="badge-gray hover:text-white">Spotify ↗</a>@endif
                    <span class="text-zinc-500">{{ $trackCount ?? 0 }} Titel{{ ($albumCount ?? 0) ? ' · '.$albumCount.' '.($albumCount === 1 ? 'Album' : 'Alben') : '' }}{{ $inboxTracks ? ' · '.$inboxTracks.' in der Inbox' : '' }}</span>
                </div>
                <label class="mt-2 flex items-start gap-2 px-1 text-sm">
                    <input type="checkbox" class="mt-0.5 rounded border-zinc-600 bg-zinc-800" @checked(in_array($artist->name_confirmed, ['manual', 'auto'], true)) wire:change="setConfirmed($event.target.checked)">
                    <span>
                        <span class="text-zinc-200">Name passt</span><span class="text-zinc-500"> – Schreibweise stimmt{{ $isCombo ? ', ein Act (nicht trennen)' : '' }}</span>
                        <span class="block text-xs {{ $artist->name_confirmed === 'auto' ? 'text-sky-300/80' : 'text-zinc-500' }}">
                            @if ($artist->name_confirmed === 'manual')
                                von dir bestätigt {{ $artist->name_confirmed_at?->diffForHumans() }}
                            @elseif ($artist->name_confirmed === 'auto')
                                automatisch: {{ $artist->name_confirmed_reason }} · <button type="button" wire:click="setConfirmed(true)" class="underline hover:text-white">als meine Entscheidung übernehmen</button>
                            @elseif ($artist->name_confirmed === 'rejected')
                                von dir zurückgenommen – die App bestätigt nicht mehr von selbst
                            @else
                                noch nicht geprüft
                            @endif
                        </span>
                    </span>
                </label>
            </div>
            <div class="flex shrink-0 flex-col items-end gap-2">
                <div class="flex items-center gap-2">
                    <button wire:click="saveArtist" @disabled(! $dirty) class="btn-primary">Speichern</button>
                    {{-- Aktionen --}}
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                        <button x-on:click="open = ! open" class="btn-ghost" title="Weitere Aktionen">⋯</button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 z-30 mt-1 w-64 space-y-0.5 rounded-xl border border-zinc-700 bg-zinc-900 p-1.5 text-sm shadow-2xl">
                            <button x-on:click="open = false; $wire.imagesOpen = true; $wire.findImages()" class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800">Bild wählen …</button>
                            @unless ($artist->base_name)
                                <button wire:click="$set('mergeOpen', true)" x-on:click="open = false" class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800" title="Andere Einträge sind nur andere Schreibweisen dieses Künstlers">Ist derselbe wie …</button>
                            @endunless
                            @if (\App\Support\ArtistNames::looksCollaboration($artist->name))
                                <button wire:click="openSplitInto" x-on:click="open = false" class="w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-800" title="Kein fester Act, sondern eine Zusammenarbeit – künftig in die einzelnen Künstler trennen">In Einzelkünstler trennen …</button>
                            @endif
                            <div class="my-1 border-t border-zinc-800"></div>
                            <button wire:click="deleteArtist" x-on:click="open = false" data-confirm-label="Löschen" data-confirm-danger wire:confirm="„{{ $artist->name }}“ aus dem Verzeichnis löschen? (Dateien bleiben unverändert)" class="w-full rounded-lg px-3 py-2 text-left text-red-300 hover:bg-red-500/10">Löschen</button>
                        </div>
                    </div>
                </div>
                @if ($dirty)<span class="text-xs text-amber-300">ungespeicherte Änderungen</span>@endif
            </div>
        </div>
</div>

<div class="min-h-0 flex-1 overflow-y-auto p-6">
<div class="max-w-5xl">

    @if ($lookupError)<p class="mb-3 text-sm text-amber-300">{{ $lookupError }}</p>@endif

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Genres --}}
        <section class="{{ $card }}">
            <h3 class="{{ $title }}">Standard-Genres <span class="normal-case tracking-normal text-zinc-500">· bis zu {{ \App\Models\Artist::MAX_GENRES }}, Vorschlag für neue Alben</span></h3>
            <div class="flex flex-wrap items-center gap-1.5">
                @forelse ($selectedGenres as $g)
                    <span class="badge-violet gap-1">{{ $g }}<button wire:click="removeGenre(@js($g))" class="opacity-60 hover:opacity-100" title="entfernen">✕</button></span>
                @empty
                    <span class="text-xs text-zinc-500">noch keins</span>
                @endforelse
                <select x-data x-on:change="$wire.addGenre($event.target.value); $event.target.value = ''" class="input-sm w-auto py-0.5 text-xs">
                    <option value="">+ Genre …</option>
                    @foreach ($genreOptions as $o)<option value="{{ $o['name'] }}" @disabled(in_array($o['name'], $selectedGenres, true))>{{ $o['label'] }}</option>@endforeach
                </select>
            </div>
            <div class="mt-3 flex flex-wrap items-start gap-2 text-xs">
                <button wire:click="lookupGenres" class="btn-ghost btn-sm shrink-0" title="Bestand, MusicBrainz, TheAudioDB, Spotify und Discogs befragen">
                    <span wire:loading.remove wire:target="lookupGenres">Vorschläge holen</span><span wire:loading wire:target="lookupGenres">sucht …</span>
                </button>
                @if (! $genreProposals && $genreSuggestion && ! in_array($genreSuggestion['genre'], $selectedGenres, true))
                    <button wire:click="addGenre(@js($genreSuggestion['genre']))" class="rounded-lg border border-zinc-700 px-2.5 py-1 text-left text-zinc-300 hover:border-zinc-500">
                        <span class="text-zinc-500">Bestand:</span> <span class="font-medium">{{ $genreSuggestion['genre'] }}</span> <span class="text-zinc-500">({{ $genreSuggestion['count'] }} von {{ $genreSuggestion['total'] }})</span>
                    </button>
                @endif
                @foreach ($genreProposals as $p)
                    @foreach (array_filter([$p['genre'] ?? null, ...($p['tied'] ?? [])]) as $g)
                        @php $on = in_array($g, $selectedGenres, true); @endphp
                        <button wire:click="{{ $on ? 'removeGenre' : 'addGenre' }}(@js($g))" title="{{ $p['detail'] }}"
                                class="rounded-lg border px-2.5 py-1 text-left {{ $on ? 'border-blue-500 bg-blue-500/10 text-blue-100' : 'border-zinc-700 text-zinc-300 hover:border-zinc-500' }}">
                            <span class="text-zinc-500">{{ $p['source'] }}:</span> <span class="font-medium">{{ $g }}</span>{{ $on ? ' ✓' : '' }}
                        </button>
                    @endforeach
                    @if (! ($p['genre'] ?? null) && empty($p['tied']))
                        <span class="rounded-lg border border-zinc-800 px-2.5 py-1 text-zinc-500" title="{{ $p['detail'] }}">{{ $p['source'] }}: {{ \Illuminate\Support\Str::limit($p['detail'], 60) }}</span>
                    @endif
                @endforeach
            </div>
        </section>

        {{-- IDs --}}
        <section class="{{ $card }}">
            <h3 class="{{ $title }}">Zuordnung</h3>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">MusicBrainz-ID</label><input wire:model.live.debounce.500ms="form.mbid" class="input font-mono text-xs"></div>
                <div><label class="label">Discogs-ID</label><input wire:model.live.debounce.500ms="form.discogs_id" class="input font-mono text-xs"></div>
            </div>
            @if (trim((string) ($form['mbid'] ?? '')) === '' || trim((string) ($form['discogs_id'] ?? '')) === '')
                <button wire:click="searchIds" class="btn-ghost btn-sm mt-3"><span wire:loading.remove wire:target="searchIds">Suchen{{ trim((string) ($form['mbid'] ?? '')) === '' && trim((string) ($form['discogs_id'] ?? '')) === '' ? '' : (trim((string) ($form['mbid'] ?? '')) === '' ? ' (MusicBrainz)' : ' (Discogs)') }}</span><span wire:loading wire:target="searchIds">sucht …</span></button>
            @endif
            @if ($mbResults)
                <div class="mt-3 text-xs text-zinc-400">MusicBrainz – Klick übernimmt die ID</div>
                <div class="mt-1 divide-y divide-zinc-800 rounded-lg border border-zinc-800">
                    @foreach ($mbResults as $i => $r)
                        <button wire:click="useMusicBrainz({{ $i }})" class="flex w-full items-center gap-3 px-3 py-1.5 text-left text-sm hover:bg-zinc-800">
                            <span class="font-medium">{{ $r['name'] }}</span><span class="truncate text-xs text-zinc-500">{{ $r['disambiguation'] }} {{ $r['country'] }}</span><span class="ml-auto text-xs text-zinc-500">{{ $r['score'] }}%</span>
                        </button>
                    @endforeach
                </div>
            @endif
            @if ($discogsResults)
                <div class="mt-3 text-xs text-zinc-400">Discogs – Klick übernimmt die ID</div>
                <div class="mt-1 divide-y divide-zinc-800 rounded-lg border border-zinc-800">
                    @foreach ($discogsResults as $i => $r)
                        <button wire:click="useDiscogs({{ $i }})" class="flex w-full items-center gap-3 px-3 py-1.5 text-left text-sm hover:bg-zinc-800">
                            @if ($r['image'])<img src="{{ $r['image'] }}" class="size-7 rounded object-cover">@endif
                            <span class="font-medium">{{ $r['name'] }}</span><span class="truncate text-xs text-zinc-500">{{ $r['raw'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Schreibweisen --}}
        <section class="{{ $card }}">
            <h3 class="{{ $title }}">Schreibweisen</h3>
            <div class="flex flex-wrap gap-1.5">
                @forelse ($artist->aliases as $al)
                    <span class="badge-gray gap-1" @if ($al->source === 'musicbrainz') title="Schreibweise laut MusicBrainz" @endif>
                        {{ $al->alias }}@if ($al->source === 'musicbrainz')<span class="text-[10px] text-emerald-300/80">MB</span>@endif
                        <button wire:click="removeAlias({{ $al->id }})" class="text-zinc-500 hover:text-red-400" title="Löschen – wird gemerkt, „Von MusicBrainz holen“ trägt sie nicht wieder ein">✕</button>
                    </span>
                @empty
                    <span class="text-xs text-zinc-500">noch keine</span>
                @endforelse
            </div>
            <div class="mt-3 flex gap-2"><input wire:model="newAlias" wire:keydown.enter="addAlias" placeholder="Schreibweise hinzufügen" class="input-sm min-w-0 flex-1"><button wire:click="addAlias" class="btn-ghost btn-sm">+</button>
                @if ($artist->mbid)
                    <button wire:click="loadMusicBrainzAliases" class="btn-ghost btn-sm whitespace-nowrap" title="Alle bei MusicBrainz bekannten Schreibweisen übernehmen (außer bürgerlichem Namen)"><span wire:loading.remove wire:target="loadMusicBrainzAliases">Von MusicBrainz</span><span wire:loading wire:target="loadMusicBrainzAliases">…</span></button>
                @endif
            </div>
            @error('newAlias')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
            @if ($artist->rejectedAliases->isNotEmpty())
                <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                    <span title="Diese Schreibweisen hast du gelöscht – MusicBrainz trägt sie nicht wieder ein">Gelöscht:</span>
                    @foreach ($artist->rejectedAliases as $r)
                        <span class="inline-flex items-center gap-1 rounded border border-zinc-800 px-1.5 py-0.5 text-zinc-500 line-through decoration-zinc-600">{{ $r->alias }}<button wire:click="allowAlias({{ $r->id }})" class="text-zinc-500 no-underline hover:text-blue-300" title="Wieder zulassen">↺</button></span>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Hörproben --}}
        <section class="{{ $card }}">
            <h3 class="{{ $title }}">Hörproben</h3>
            @if ($artistTracks)
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($artistTracks as $t)
                        <div class="flex items-center gap-2 rounded-lg border border-zinc-800 px-2 py-1 text-xs" wire:key="at{{ $t['id'] }}">
                            <x-play-button :id="$t['id']" :seconds="$t['seconds']" :title="$t['title']" :artist="$t['artist']" :album="$t['album']" size="size-6" />
                            <span class="max-w-48 truncate" title="{{ $t['title'] }} – {{ $t['album'] }}">{{ $t['title'] }} <span class="text-zinc-500">· {{ $t['album'] }}</span></span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-zinc-500">Keine Lieder im Bestand unter diesem Namen oder seinen Schreibweisen.@if ($inboxTracks) {{ $inboxTracks }} {{ $inboxTracks === 1 ? 'Lied liegt' : 'Lieder liegen' }} noch in der Inbox – sie erscheinen hier nach dem Einordnen.@endif</p>
            @endif
        </section>

        {{-- Kombi-Prüfung --}}
        @if ($combiApplies)
            <section class="{{ $card }} {{ ($combi['verdict'] ?? null) === 'collab' && $artist->name_confirmed !== 'manual' ? 'border-amber-500/30 bg-amber-500/5' : '' }}">
                <div class="mb-2 flex items-center gap-2">
                    <h3 class="{{ $title }} mb-0 flex-1">Kombi-Prüfung</h3>
                    <button wire:click="checkCombiNow" class="btn-ghost btn-sm"><span wire:loading.remove wire:target="checkCombiNow">{{ $combi ? 'neu prüfen' : 'jetzt prüfen' }}</span><span wire:loading wire:target="checkCombiNow">prüft …</span></button>
                </div>
                @if ($combi)<x-combi-verdict :combi="$combi" />@else<p class="text-xs text-zinc-500">noch nicht geprüft</p>@endif
                @if ($combi && $combi['evidence'])
                    <table class="mt-2 w-full text-xs">
                        @foreach ($combi['evidence'] as $e)
                            <tr class="align-top">
                                <td class="w-24 pr-2 text-zinc-500">{{ $e['source'] }}</td>
                                <td class="max-w-48 truncate pr-2 text-zinc-400" title="{{ $e['title'] }}">{{ $e['title'] }}</td>
                                <td class="pr-2 {{ $e['vote'] === 'collab' ? 'text-amber-300' : ($e['vote'] === 'act' ? 'text-emerald-300' : 'text-zinc-500') }}">{{ implode(' + ', $e['credited']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                @if ($combi)
                    <p class="mt-1 text-xs text-zinc-500">Bestand: {{ $combi['local']['own_albums'] }} eigene(s) Album/Alben{{ $combi['local']['parts'] ? ' · solo: '.collect($combi['local']['parts'])->map(fn ($n, $p) => $p.' '.$n)->implode(', ') : '' }} · geprüft {{ \Illuminate\Support\Carbon::parse($combi['checked_at'])->diffForHumans() }}</p>
                @endif
                @if (\App\Support\ArtistNames::looksCollaboration($artist->name))
                    <button wire:click="openSplitInto" class="btn-ghost btn-sm mt-2">In Einzelkünstler trennen …</button>
                @endif
            </section>
        @endif

        {{-- TheAudioDB --}}
        @if ($audiodb)
            <section class="{{ $card }}" x-data="{ open: false }">
                <h3 class="{{ $title }}">TheAudioDB</h3>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400">
                    @if ($audiodb['genre'] || $audiodb['style'])<span>{{ collect([$audiodb['genre'], $audiodb['style']])->filter()->unique()->implode(' / ') }}</span>@endif
                    @if ($audiodb['members'])<span>{{ $audiodb['members'] === 1 ? 'Solo' : $audiodb['members'].' Mitglieder' }}</span>@endif
                    @if ($audiodb['country'])<span>{{ $audiodb['country'] }}</span>@endif
                    @if ($audiodb['biography'])<button x-on:click="open = ! open" class="ml-auto text-zinc-400 hover:text-white" x-text="open ? 'Biografie ausblenden' : 'Biografie{{ $audiodb['biography_lang'] === 'en' ? ' (englisch)' : '' }}'"></button>@endif
                </div>
                @if ($audiodb['biography'])<p x-show="open" x-cloak class="mt-2 max-h-64 overflow-y-auto text-xs whitespace-pre-line text-zinc-300">{{ $audiodb['biography'] }}</p>@endif
            </section>
        @endif
    </div>

    {{-- Gleichnamige Künstler --}}
    @include('livewire.partials.homonyms')
</div>
</div>
