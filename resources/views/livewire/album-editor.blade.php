@php
    $isInbox = $album->location === 'inbox';
    $isCompilation = (bool) ($category['compilation'] ?? false);
    $rel = fn ($p) => str_starts_with((string) $p, $base.'/') ? substr($p, strlen($base) + 1) : $p;
@endphp
<div class="flex h-full flex-col overflow-hidden" @if ($operation) wire:poll.1s="checkOperation" @endif>
    <x-page-header :title="$fields['album'] ?: $album->title" :subtitle="$album->discCount() > 1 ? $album->discCount().' CDs: '.implode(' · ', array_map(fn ($d) => basename($d), $album->allDirs())) : $rel($album->dir)">
        <a href="{{ $isInbox ? route('inbox') : route('library', $album->is_compilation ? ['sampler' => 1] : ['artist' => $album->folder_artist]) }}" wire:navigate class="btn-ghost">← {{ $isInbox ? 'Inbox' : 'Lib' }}</a>
    </x-page-header>

    @if ($existing && ($existing['album'] || $existing['songs']))
        <div class="shrink-0 px-6 pt-4">
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
                @php $cds = fn ($d) => $d ? 'CD '.implode(', ', $d) : ''; @endphp
                @if ($existing['album'] && $existing['duplicate'])
                    <strong>Dieses Album gibt es schon in der Lib:</strong>
                    <a href="{{ route('library.album', $existing['album']) }}" wire:navigate class="underline">{{ $rel($existing['album']->dir) }}</a>
                    – {{ $existing['songs'] }} von {{ $existing['total'] }} Liedern sind schon da (in der Liste markiert).
                    Beim Einordnen entscheidet die Vorschau pro Datei (bessere Version behalten).
                @elseif ($existing['album'])
                    <strong>Ein Teil dieses Albums liegt schon in der Lib:</strong>
                    <a href="{{ route('library.album', $existing['album']) }}" wire:navigate class="underline">{{ $rel($existing['album']->dir) }}</a>.
                    Dort: {{ $cds($existing['album_discs']) }} ({{ $existing['album_tracks'] }} {{ $existing['album_tracks'] === 1 ? 'Lied' : 'Lieder' }}) – hier: {{ $cds($existing['inbox_discs']) }} ({{ $existing['total'] }} Lieder){{ $existing['songs'] ? ', davon '.$existing['songs'].' schon vorhanden' : ', keines davon doppelt' }}.
                    Gehört vermutlich zusammen – mit gleicher Kategorie und gleichem Albumnamen landet beides im selben Ordner.
                @else
                    {{ $existing['songs'] }} von {{ $existing['total'] }} Liedern gibt es schon in der Lib – in der Liste markiert:
                @endif
                @if (! ($existing['album'] && $existing['duplicate']) && ! empty($existing['where']))
                    <span class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                        @foreach ($existing['where'] as $w)
                            @if ($w['album'] && basename($w['album']->dir) === $noAlbum)
                                <a href="{{ route('library.album', $w['album']) }}" wire:navigate class="underline" title="{{ $rel($w['album']->dir) }}">{{ $w['count'] }}× ohne Album ({{ $w['album']->folder_artist ?: $w['album']->albumartist }})</a>
                            @elseif ($w['album'])
                                <a href="{{ route('library.album', $w['album']) }}" wire:navigate class="underline" title="{{ $rel($w['album']->dir) }}">{{ $w['count'] }}× in „{{ $w['album']->title }}“{{ $w['album']->is_compilation ? ' (Sampler)' : ($w['album']->albumartist ? ' – '.$w['album']->albumartist : '') }}</a>
                            @else
                                <span>{{ $w['count'] }}× ohne Album</span>
                            @endif
                        @endforeach
                    </span>
                @endif
            </div>
        </div>
    @endif

    @if ($album->discCount() > 1 && ($missingDiscs = $album->missingDiscs()))
        <div class="shrink-0 px-6 pt-4">
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
                Vorhanden: CD {{ implode(', ', $album->discNumbers()) }} – <strong>CD {{ implode(', ', $missingDiscs) }} fehlt</strong> in der Inbox.
                Du kannst trotzdem einordnen; kommt die fehlende CD später, landet sie mit gleichem Albumnamen im selben Ordner.
            </div>
        </div>
    @endif

    @if (session('status') || session('error'))
        <div class="shrink-0 px-6 pt-4">
            @if (session('status'))
                <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-200">
                    {{ session('status') }}
                    @if (session('filed_url'))<a href="{{ session('filed_url') }}" wire:navigate class="ml-2 underline">ansehen</a>@endif
                </div>
            @endif
            @if (session('error'))<div class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm whitespace-pre-line text-red-200">{{ session('error') }}</div>@endif
        </div>
    @endif

    {{-- Künstler, die laut Verzeichnis (bzw. MusicBrainz) anders heißen: umbenennen vorschlagen --}}
    @if ($renames)
        <div class="shrink-0 px-6 pt-4">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-blue-500/30 bg-blue-500/10 px-4 py-2 text-sm text-blue-100">
                <span class="font-medium">Künstler umbenennen?</span>
                @foreach ($renames as $from => $r)
                    <span>„{{ $from }}“ → <strong>„{{ $r['to'] }}“</strong> <span class="text-xs text-blue-300/70">(Schreibweise laut {{ $r['source'] }})</span></span>
                @endforeach
                <button wire:click="applyAllArtistCorrections" class="btn-primary btn-sm ml-auto">Umbenennen</button>
            </div>
            <p class="mt-1 text-xs text-zinc-500">Ändert nur die Felder unten – geschrieben wird erst beim Speichern bzw. Einordnen (mit Vorschau).</p>
        </div>
    @endif

    <div class="flex min-h-0 flex-1 overflow-hidden">
        {{-- Album-Felder --}}
        <section class="w-96 shrink-0 space-y-5 overflow-y-auto border-r border-zinc-800 p-6">
            <div x-data="{ over: false }"
                 x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
                 x-on:drop.prevent="over = false; if ($event.dataTransfer.files.length) $wire.upload('coverUpload', $event.dataTransfer.files[0])"
                 class="relative">
                @if ($newCover)
                    <img src="{{ route('cover.tmp', ['f' => basename($newCover)]) }}" class="aspect-square w-full rounded-xl object-cover ring-2 ring-violet-500">
                    <span class="badge-violet absolute top-3 left-3">neues Cover</span>
                @elseif ($removeCover)
                    <div class="relative">
                        <x-cover :url="$album->coverUrl(800)" class="aspect-square w-full rounded-xl opacity-25 grayscale" />
                        <span class="badge-red absolute top-3 left-3">wird entfernt</span>
                    </div>
                @else
                    <x-cover :url="$album->coverUrl(800)" class="aspect-square w-full rounded-xl" />
                @endif
                <div x-show="over" class="absolute inset-0 grid place-items-center rounded-xl border-2 border-dashed border-blue-400 bg-blue-500/20 text-sm font-medium text-blue-100">Bild hier ablegen</div>
                <div wire:loading wire:target="coverUpload" class="absolute inset-0 grid place-items-center rounded-xl bg-zinc-950/70 text-sm">Lädt …</div>
            </div>
            <div class="flex items-center gap-2 text-xs text-zinc-400">
                <label class="btn-ghost btn-sm cursor-pointer">Cover hochladen<input type="file" accept="image/*" wire:model="coverUpload" class="hidden"></label>
                @if ($newCover || $removeCover)
                    <button wire:click="discardCover" class="btn-ghost btn-sm">Verwerfen</button>
                @elseif ($album->hasCover())
                    <button wire:click="markRemoveCover" class="btn-ghost btn-sm hover:text-red-300">Entfernen</button>
                @endif
                <span class="ml-auto">
                    @if ($album->cover_embedded) eingebettet @elseif ($album->cover_file) {{ basename($album->cover_file) }} @else kein Cover @endif
                    @if ($album->cover_size) · {{ $album->cover_size }} px @endif
                </span>
            </div>
            <div class="flex gap-2">
                <input type="url" wire:model="coverUrl" wire:keydown.enter="loadCoverUrl" placeholder="oder Bild-URL einfügen …" class="input-sm text-xs">
                <button wire:click="loadCoverUrl" class="btn-ghost btn-sm shrink-0" wire:loading.attr="disabled" wire:target="loadCoverUrl">
                    <span wire:loading.remove wire:target="loadCoverUrl">Laden</span><span wire:loading wire:target="loadCoverUrl">lädt …</span>
                </button>
            </div>
            @error('coverUpload')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
            @error('coverUrl')<p class="text-sm text-red-400">{{ $message }}</p>@enderror

            @if ($album->location === 'inbox')
                <div class="rounded-lg border border-zinc-800 p-3">
                    <div class="flex items-center">
                        <label class="label mb-0">CDs</label>
                        <button wire:click="openAddDisc" class="ml-auto text-xs text-blue-400 hover:underline">+ CD hinzufügen …</button>
                    </div>
                    @if (count($discMap) > 1)
                        <ul class="mt-2 space-y-1 text-xs">
                            @foreach ($discMap as $dir => $disc)
                                <li class="flex items-center gap-2" wire:key="disc{{ md5($dir) }}">
                                    <span class="badge-blue shrink-0">CD {{ $disc }}</span>
                                    <span class="min-w-0 flex-1 truncate text-zinc-400" title="{{ $dir }}">{{ basename($dir) }}</span>
                                    @if (in_array($dir, $manualDirs, true))
                                        <button wire:click="removeDisc(@js($dir))" data-confirm-label="Trennen" wire:confirm="CD {{ $disc }} wieder als eigenes Album führen?" class="shrink-0 text-zinc-500 hover:text-red-300" title="herauslösen">✕</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($addDiscOpen)
                        <div class="mt-3 space-y-2 border-t border-zinc-800 pt-3">
                            <div class="text-xs text-zinc-400">Welches Album ist eine weitere CD? (gleicher Ordner, nach Ähnlichkeit)</div>
                            <div class="max-h-64 space-y-1 overflow-y-auto">
                                @forelse ($discCandidates as $c)
                                    <label class="flex cursor-pointer items-start gap-2 rounded px-1.5 py-1 text-xs hover:bg-zinc-800 {{ $addDiscAlbum === $c['album']->id ? 'bg-blue-500/10 ring-1 ring-blue-500/40' : '' }}" wire:key="dc{{ $c['album']->id }}">
                                        <input type="radio" wire:model.live="addDiscAlbum" value="{{ $c['album']->id }}" class="mt-0.5 border-zinc-600 bg-zinc-800">
                                        <span class="min-w-0">
                                            <span class="block truncate text-zinc-200" title="{{ $c['album']->dir }}">{{ $c['album']->title }}</span>
                                            <span class="block text-zinc-500">{{ $c['album']->track_count }} Tracks @if ($c['album']->year)· {{ $c['album']->year }}@endif @if ($c['reasons'])· <span class="text-blue-300/80">{{ implode(' · ', $c['reasons']) }}</span>@endif</span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="text-xs text-zinc-500">Keine anderen Alben in diesem Ordner.</p>
                                @endforelse
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-zinc-400">als CD</span>
                                <input type="number" min="1" wire:model="addDiscNumber" class="input-sm w-16">
                                <button wire:click="addDisc" class="btn-primary btn-sm ml-auto" @disabled(! $addDiscAlbum)>Hinzufügen</button>
                                <button wire:click="$set('addDiscOpen', false)" class="btn-ghost btn-sm">✕</button>
                            </div>
                            @error('addDisc')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
                            <p class="text-[11px] text-zinc-500">Bleibt bei jedem Scan erhalten. Geschrieben wird erst beim Speichern/Einordnen (CD-Nummer, Albumname).</p>
                        </div>
                    @endif
                </div>
            @endif

            <div>
                <label class="label">Kategorie</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($categories as $c)
                        <button wire:click="$set('fields.category', @js($c['name']))"
                                class="rounded-lg border px-2 py-2 text-xs font-semibold tracking-wide transition {{ $fields['category'] === $c['name'] ? 'border-violet-500 bg-violet-500/15 text-violet-200' : 'border-zinc-700 bg-zinc-900 text-zinc-400 hover:border-zinc-600 hover:text-zinc-200' }}">
                            {{ $c['name'] }}
                        </button>
                    @endforeach
                </div>
                @if ($categoryGuessed && $fields['category'] === $categoryGuessed)
                    <p class="mt-1 text-xs text-zinc-500">Vorausgewählt: {{ $categoryGuessedReason }}</p>
                @endif
                @error('category')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="label">Album</label>
                <input type="text" wire:model.live.debounce.500ms="fields.album" class="input" placeholder="leer = „{{ $noAlbum }}“ (Einzellieder)">
                @if (trim($fields['album']) === '')
                    <p class="mt-1 text-xs text-zinc-500">Ohne Albumname: Ablage in „{{ $noAlbum }}“, Track- und CD-Nummern werden geleert.</p>
                @endif
            </div>
            <div>
                <label class="label">Album-Interpret</label>
                @if ($isCompilation)
                    <input type="text" value="{{ $compilationArtist }}" disabled class="input opacity-60">
                    <p class="mt-1 text-xs text-zinc-500">Compilation: Album-Interpret und Flag werden gesetzt.</p>
                @else
                    <div class="flex gap-2">
                        <input type="text" wire:model.live.debounce.500ms="fields.albumartist" class="input">
                        <button wire:click="copyArtistToAlbumArtist" title="Interpret der Tracks übernehmen" class="btn-ghost btn-sm">↵</button>
                    </div>
                    @if ($albumArtistFix)
                        <button wire:click="applyAlbumArtist(@js($albumArtistFix))" class="mt-1 text-xs text-blue-400 hover:underline">Schreibweise: {{ $albumArtistFix }} übernehmen</button>
                    @endif
                    @if ($vaWarning)
                        <p class="mt-1 text-xs text-amber-300">„{{ $fields['albumartist'] }}“ ist ein Sampler-Album-Interpret, aber die Kategorie ist keine Compilation.</p>
                    @endif
                    @foreach ($albumArtistNew as $n)
                        <button wire:click="$dispatch('open-new-artist', { name: @js($n), album: {{ $album->id }} })" class="mt-1 mr-2 text-xs text-violet-300 hover:underline" title="Im Künstlerverzeichnis erfassen (MusicBrainz, Bild, Genre)">neu: {{ $n }} erfassen …</button>
                    @endforeach
                @endif
            </div>
            @if ($persons)
                <div class="rounded-lg border border-violet-500/40 bg-violet-500/5 p-3">
                    <label class="label text-violet-200">Gleichnamige Künstler – welche Person?</label>
                    @foreach ($persons as $i => $p)
                        <div class="mt-2" wire:key="person{{ $i }}">
                            <div class="text-xs text-zinc-400">„{{ $p['base'] }}“</div>
                            <select wire:model.live="persons.{{ $i }}.full" class="input-sm {{ $p['full'] === '' ? 'border-amber-500/60' : '' }}">
                                <option value="">— bitte wählen —</option>
                                @foreach ($personOptions[$i] ?? [] as $o)<option value="{{ $o['name'] }}">{{ $o['label'] }}</option>@endforeach
                            </select>
                        </div>
                    @endforeach
                    <p class="mt-2 text-[11px] text-zinc-500">Steht als Zusatz in ARTISTS; der Anzeigetext bleibt. Ohne Auswahl wird nichts geschrieben.</p>
                </div>
            @endif

            <div>
                <label class="label">Jahr</label>
                <div class="flex items-center gap-3">
                    <input type="text" wire:model.live.debounce.500ms="fields.year" class="input w-32" placeholder="z. B. 1996">
                    <button wire:click="lookupGenres" class="text-xs text-blue-400 hover:underline">
                        <span wire:loading.remove wire:target="lookupGenres">Jahr &amp; Genre nachschlagen</span><span wire:loading wire:target="lookupGenres">sucht …</span>
                    </button>
                </div>
            </div>

            @if ($looseFolder)
                <div>
                    <label class="label">Genre</label>
                    <p class="text-xs text-zinc-500">„Kein Album“ = Einzellieder: Genre pro Lied in der Trackliste.</p>
                </div>
            @else
            <div>
                <div class="flex items-center justify-between">
                    <label class="label">Genre {{ $isCompilation ? '(Album, pro Track änderbar)' : '' }}</label>
                    <button wire:click="lookupGenres" class="text-xs text-blue-400 hover:underline">
                        <span wire:loading.remove wire:target="lookupGenres">Nachschlagen</span><span wire:loading wire:target="lookupGenres">sucht …</span>
                    </button>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse ($fields['genres'] as $g)
                        <button wire:click="toggleGenre(@js($g))" class="badge-blue hover:bg-blue-500/30">{{ $g }} ✕</button>
                    @empty
                        <span class="text-sm text-zinc-500">kein Genre</span>
                    @endforelse
                    <x-genre-picker :options="$genreOptions" :selected="$fields['genres']" wire:key="gp-album-{{ implode('|', $fields['genres']) }}" />
                </div>
                @php $differing = $perTrackGenres && $fields['genres'] ? collect($rows)->filter(fn ($r) => $r['genres'] && $r['genres'] !== $fields['genres'])->count() : 0; @endphp
                @if ($differing)
                    <button wire:click="applyGenreToAllTracks" class="mt-2 block text-xs text-blue-400 hover:underline">
                        Album-Genre auf alle Titel übernehmen ({{ $differing }} {{ $differing === 1 ? 'Titel hat' : 'Titel haben' }} ein anderes Genre)
                    </button>
                @endif
                @if ($genreSuggestion && $genreSuggestion !== $fields['genres'])
                    <button wire:click="useGenres(@js($genreSuggestion))" class="mt-2 block text-xs text-blue-400 hover:underline">Vorschlag aus Zuordnung: {{ implode(', ', $genreSuggestion) }}</button>
                @endif
                @if ($defaultGenre && ! in_array($defaultGenre, $fields['genres']))
                    <button wire:click="useGenres(@js([$defaultGenre]))" class="mt-1 block text-xs text-blue-400 hover:underline">Standard-Genre des Künstlers: {{ $defaultGenre }}</button>
                @endif
                @if ($unlistedGenres)
                    <p class="mt-1 text-xs text-zinc-500">Im Tag (nicht auf der Liste): {{ implode(', ', $unlistedGenres) }}</p>
                @endif
                @foreach ($genreLookup as $hit)
                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-2 text-xs" wire:key="lk{{ $loop->index }}">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-zinc-200" title="{{ $hit['title'] }}">{{ $hit['title'] }}</div>
                                <div class="text-zinc-500">{{ $hit['source'] }}{{ $hit['artist'] ? ' · '.$hit['artist'] : '' }}{{ $hit['year'] ? ' · '.$hit['year'] : '' }}{{ $hit['tracks'] ? ' · '.$hit['tracks'].' Tracks' : '' }}</div>
                            </div>
                            @if ($hit['url'])<a href="{{ $hit['url'] }}" target="_blank" rel="noopener" class="text-zinc-500 hover:text-white" title="Quelle öffnen">↗</a>@endif
                        </div>
                        @if ($hit['genres'])<div class="mt-1 text-zinc-400">{{ implode(', ', array_slice($hit['genres'], 0, 6)) }}</div>@endif
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                            @if ($hit['year'] && $hit['year'] !== $fields['year'])
                                <button wire:click="useYear(@js($hit['year']))" class="text-blue-400 hover:underline">Jahr {{ $hit['year'] }} übernehmen</button>
                            @endif
                            @if ($hit['suggestion'] && $hit['suggestion'] !== $fields['genres'])
                                <button wire:click="useGenres(@js($hit['suggestion']))" class="text-blue-400 hover:underline">Genre {{ implode(', ', $hit['suggestion']) }} übernehmen</button>
                            @elseif (! $hit['suggestion'] && $hit['genres'])
                                <span class="text-zinc-500">Genre ohne Zuordnung – in Einstellungen › Genre-Zuordnung pflegen.</span>
                            @endif
                            @if (! empty($hit['mbid']))
                                <button wire:click="compareTracklist({{ $loop->index }})" class="text-blue-400 hover:underline">
                                    <span wire:loading.remove wire:target="compareTracklist({{ $loop->index }})">Titelliste vergleichen</span><span wire:loading wire:target="compareTracklist({{ $loop->index }})">lädt …</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
                @if ($tracklist)
                    <div class="mt-2 rounded-lg border border-blue-500/30 bg-blue-500/5 p-2 text-xs">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1 text-zinc-200">MusicBrainz: {{ $tracklist['release'] }}</div>
                            <a href="{{ $tracklist['url'] }}" target="_blank" rel="noopener" class="text-zinc-500 hover:text-white" title="Quelle öffnen">↗</a>
                            <button wire:click="$set('tracklist', null)" class="text-zinc-500 hover:text-white">✕</button>
                        </div>
                        <div class="mt-1 text-zinc-400">{{ count($rows) }} von {{ $tracklist['count'] }} Titeln{{ ! $tracklist['missing'] && ! $tracklist['titles'] && ! $tracklist['numbers'] ? ' – alles passt.' : '' }}</div>
                        @if ($tracklist['missing'])
                            <div class="mt-1 text-amber-300">Es fehlen: {{ implode(' · ', array_slice($tracklist['missing'], 0, 20)) }}{{ count($tracklist['missing']) > 20 ? ' …' : '' }}</div>
                        @endif
                        @if ($tracklist['titles'])
                            <div class="mt-1 text-zinc-300">Titel weichen ab:
                                @foreach (array_slice($tracklist['titles'], 0, 8, true) as $i => $t)<div class="ml-2 text-zinc-400">{{ $rows[$i]['track'] }}. {{ $rows[$i]['title'] ?: '∅' }} → <span class="text-zinc-200">{{ $t }}</span></div>@endforeach
                                @if (count($tracklist['titles']) > 8)<div class="ml-2 text-zinc-500">… und {{ count($tracklist['titles']) - 8 }} weitere</div>@endif
                            </div>
                            <button wire:click="applyTracklistTitles" class="mt-1 text-blue-400 hover:underline">{{ count($tracklist['titles']) }} Titel übernehmen</button>
                        @endif
                        @if ($tracklist['numbers'])
                            <button wire:click="applyTracklistNumbers" class="mt-1 block text-blue-400 hover:underline">Nummern für {{ count($tracklist['numbers']) }} Titel ohne passende Nummer übernehmen</button>
                        @endif
                    </div>
                @endif
                @if ($lookupError)<p class="mt-1 text-xs text-amber-300">{{ $lookupError }}</p>@endif
            </div>
            @endif
        </section>

        {{-- Trackliste --}}
        <section class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-zinc-800 px-4 py-2.5 text-sm">
                <button wire:click="renumber" class="btn-ghost btn-sm">Tracks durchnummerieren</button>
                <div class="flex items-center gap-1">
                    <span class="text-zinc-400">Disc# für alle:</span>
                    <input type="number" min="1" wire:model="discForAll" class="input-sm w-16">
                    <button wire:click="setDiscForAll" class="btn-ghost btn-sm">setzen</button>
                </div>
                @if ($hints && collect($hints)->contains('type', 'fix'))
                    <button wire:click="applyAllArtistCorrections" class="btn-ghost btn-sm text-blue-300">Alle Schreibweisen übernehmen</button>
                @endif
                @php
                    $db = fn ($v) => $v === null || $v === '' ? null : str_replace(['-', '.'], ['−', ','], trim(preg_replace('/\s*db$/i', '', (string) $v))).' dB';
                    $rgAlbum = collect($rows)->pluck('rg_album')->filter()->countBy()->sortDesc()->keys()->first();
                    $rgCount = collect($rows)->pluck('rg')->filter()->count();
                @endphp
                <span class="flex items-center gap-2 text-zinc-500" title="ReplayGain: Lautstärke-Ausgleich (EBU R128, Bezug −18 LUFS). Messen dekodiert jeden Titel einmal komplett.">
                    ReplayGain{{ $rgAlbum ? ': Album '.$db($rgAlbum) : '' }}{{ $rgCount && $rgCount < count($rows) ? ' ('.$rgCount.' von '.count($rows).')' : '' }}
                    <button wire:click="measureReplaygain" class="btn-ghost btn-sm" @disabled($operation)>{{ $rgCount ? 'neu messen' : 'messen' }}</button>
                    @if ($rgCount)<button wire:click="removeReplaygain" data-confirm-label="Entfernen" wire:confirm="ReplayGain aus allen Titeln dieses Albums entfernen? (rückgängig machbar)" class="btn-ghost btn-sm" @disabled($operation)>entfernen</button>@endif
                </span>
                @if ($hasAcoustid)
                    <button wire:click="identifyTracks" class="btn-ghost btn-sm" title="Titel ohne Titel/Interpret (sonst alle, höchstens 25) per AcoustID am Klang erkennen – nur Vorschläge">
                        <span wire:loading.remove wire:target="identifyTracks">Am Fingerabdruck erkennen</span><span wire:loading wire:target="identifyTracks">erkennt …</span>
                    </button>
                    @if (count($acoustid) > 1)<button wire:click="useAllAcoustid" class="btn-ghost btn-sm text-blue-300">Alle {{ count($acoustid) }} Vorschläge übernehmen</button>@endif
                    @if ($acoustidError)<span class="text-amber-300">{{ $acoustidError }}</span>@endif
                @endif
                <span class="ml-auto flex items-center gap-2 text-zinc-500" title="Album bewerten – setzt alle Titel">
                    Album: <x-stars :value="collect($rows)->pluck('rating')->filter()->avg()" method="rateAlbum" :id="$album->id" />
                </span>
                <span class="text-zinc-500">{{ count($rows) }} Tracks · {{ $album->durationLabel() }}</span>
            </div>
            <div class="min-h-0 flex-1 overflow-auto">
                <table class="w-full text-sm">
                    <thead>
                    <tr>
                        <th class="th w-14">Disc</th>
                        <th class="th w-14">Nr</th>
                        <th class="th">Titel</th>
                        <th class="th">Interpret</th>
                        @if ($perTrackGenres)<th class="th w-40">Genre</th>@endif
                        <th class="th w-16 text-right">Länge</th>
                        <th class="th w-20 text-right" title="ReplayGain: Lautstärke-Ausgleich dieses Titels (Bezug −18 LUFS)">ReplayGain</th>
                        <th class="th w-28">Bewertung</th>
                        <th class="th w-10"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @php
                        $discCounts = array_count_values(array_map(fn ($r) => (string) $r['disc'], $rows));
                        $showDiscHeaders = count($discCounts) > 1 || $album->discCount() > 1;
                        $prevDisc = null;
                        $cols = $perTrackGenres ? 9 : 8;
                    @endphp
                    @foreach ($rows as $i => $row)
                        @if ($showDiscHeaders && (string) $row['disc'] !== $prevDisc)
                            @php
                                $prevDisc = (string) $row['disc'];
                                $groupDirs = [];
                                for ($j = $i; $j < count($rows) && (string) $rows[$j]['disc'] === $prevDisc; $j++) { $groupDirs[basename($rows[$j]['dir'])] = true; }
                                $groupSize = $j - $i;
                            @endphp
                            <tr wire:key="dh{{ $i }}-{{ $row['disc'] }}" class="bg-zinc-900">
                                <td colspan="{{ $cols }}" class="border-t-2 border-violet-500/40 px-2 py-2">
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm font-semibold tracking-wide text-violet-200">CD</span>
                                        <input type="number" min="1" value="{{ $row['disc'] }}" title="Nummer dieser CD ändern (gilt für alle {{ $groupSize }} Tracks)"
                                               wire:change="setDiscGroup({{ $i }}, $event.target.value)" wire:key="dhi{{ $i }}-{{ $row['disc'] }}"
                                               class="input-sm w-16 text-center font-semibold">
                                        <span class="text-xs text-zinc-400">{{ $groupSize }} Tracks</span>
                                        @if ($album->discCount() > 1)
                                            <span class="min-w-0 truncate text-xs text-zinc-500" title="{{ implode("\n", array_keys($groupDirs)) }}">· {{ implode(' · ', array_keys($groupDirs)) }}</span>
                                        @endif
                                        @if (($discCounts[$prevDisc] ?? 0) > $groupSize)
                                            <span class="badge-amber" title="Diese CD-Nummer kommt an mehreren Stellen vor">CD {{ $prevDisc }} doppelt?</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                        <tr wire:key="r{{ $row['id'] }}" x-data :class="$store.player.isCurrent({{ $row['id'] }}) ? 'bg-blue-500/10' : 'hover:bg-zinc-900/60'">
                            @if (trim($fields['album']) === '')
                                <td class="td text-center text-zinc-600" title="Ohne Albumname (Kein Album) gibt es keine CD-Nummer">—</td>
                                <td class="td text-center text-zinc-600" title="Ohne Albumname (Kein Album) gibt es keine Tracknummer">—</td>
                            @else
                                <td class="td"><input type="text" wire:model.blur="rows.{{ $i }}.disc" class="input-sm w-12 text-center"></td>
                                <td class="td"><input type="text" wire:model.blur="rows.{{ $i }}.track" class="input-sm w-12 text-center"></td>
                            @endif
                            <td class="td">
                                <input type="text" wire:model.blur="rows.{{ $i }}.title" class="input-sm">
                                @if (isset($acoustid[$i]))
                                    <button wire:click="useAcoustid({{ $i }})" class="mt-0.5 block text-left text-[11px] text-blue-400 hover:underline" title="AcoustID, Sicherheit {{ round($acoustid[$i]['score'] * 100) }} %{{ $acoustid[$i]['album'] ? ' · Album: '.$acoustid[$i]['album'] : '' }}">≈ {{ $acoustid[$i]['artist'] }} – {{ $acoustid[$i]['title'] }} ({{ round($acoustid[$i]['score'] * 100) }} %) übernehmen</button>
                                @endif
                                <div class="mt-0.5 flex items-center gap-2 truncate text-[11px] text-zinc-500" title="{{ $row['filename'] }}">
                                    <span class="truncate" title="{{ $row['filename'] }}">{{ $row['filename'] }}</span>
                                    @if (! $row['writable'])<span class="badge-amber">{{ $row['ext'] }}: nur lesen</span>@endif
                                    @if (! empty($existing['tracks'][$row['id']]))
                                        @php $hit = $existing['tracks'][$row['id']]; @endphp
                                        <span class="badge-amber" title="{{ implode("\n", array_map(fn ($h) => $rel($h['path']).' ('.strtoupper($h['ext']).(in_array($h['ext'], ['flac', 'wav']) ? '' : ', '.round(($h['bitrate'] ?? 0) / 1000).' kbit/s').')', $hit)) }}">
                                            in der Lib: {{ \Illuminate\Support\Str::limit($hit[0]['album'] ?: 'ohne Album', 28) }} · {{ implode(', ', array_unique(array_map(fn ($h) => strtoupper($h['ext']), $hit))) }}{{ count($hit) > 1 ? ' ('.count($hit).'×)' : '' }}
                                        </span>
                                    @endif
                                    @if (($row['seconds'] ?? 1) < 0.1)
                                        <span class="badge-red" title="Die Datei enthält keine abspielbaren Audiodaten (z. B. abgebrochener Rip).">defekt – keine Audiodaten</span>
                                    @elseif ($row['error'])
                                        <span class="badge-red" title="{{ $row['error'] }}">Lesefehler</span>
                                    @endif
                                </div>
                            </td>
                            <td class="td">
                                <input type="text" wire:model.live.debounce.600ms="rows.{{ $i }}.artist" class="input-sm {{ trim($row['artist']) === '' ? 'border-red-500/60' : '' }}">
                                @if (isset($hints[$i]))
                                    @if ($hints[$i]['type'] === 'fix')
                                        <button wire:click="applyArtist({{ $i }}, @js($hints[$i]['value']))" class="mt-0.5 text-[11px] text-blue-400 hover:underline">→ {{ $hints[$i]['value'] }}</button>
                                    @else
                                        <span class="mt-0.5 flex flex-wrap gap-x-2 text-[11px] text-violet-300">neu:
                                            @foreach ($hints[$i]['names'] as $n)
                                                <button wire:click="$dispatch('open-new-artist', { name: @js($n), album: {{ $album->id }} })" class="hover:underline" title="Im Künstlerverzeichnis erfassen (MusicBrainz, Bild, Genre)">{{ $n }} erfassen …</button>
                                            @endforeach
                                        </span>
                                    @endif
                                @endif
                            </td>
                            @if ($perTrackGenres)
                                <td class="td">
                                    <x-genre-picker :options="$genreOptions" :selected="$row['genres']" :row="$i" compact placeholder="{{ $looseFolder ? '— wählen —' : '(wie Album)' }}" wire:key="gp-{{ $row['id'] }}-{{ implode('|', $row['genres']) }}" />
                                </td>
                            @endif
                            <td class="td text-right text-zinc-400 tabular-nums">{{ $row['duration'] }}</td>
                            <td class="td text-right tabular-nums text-xs {{ $row['rg'] ? 'text-zinc-300' : 'text-zinc-600' }}" title="{{ $row['rg'] ? 'Titel: '.$db($row['rg']).($row['rg_album'] ? ' · Album: '.$db($row['rg_album']) : '') : 'Kein ReplayGain' }}">{{ $db($row['rg']) ?? '–' }}</td>
                            <td class="td">@if ($row['writable'])<x-stars :value="$row['rating'] ?? null" method="rateTrack" :id="$row['id']" />@endif</td>
                            <td class="td">
                                <x-play-button :id="$row['id']" :seconds="$row['seconds'] ?? null" :title="$row['title']" :artist="$row['artist']" :album="$fields['album']" :cover="$album->coverUrl(120)" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <footer class="flex shrink-0 items-center gap-3 border-t border-zinc-800 bg-zinc-900/80 px-4 py-3">
                @if ($operation)
                    <span class="flex items-center gap-2 text-sm text-violet-200"><span class="size-2 animate-pulse rounded-full bg-violet-400"></span> {{ $operation->statusLabel() }}: {{ $operation->done }}/{{ $operation->total }}</span>
                @else
                    <span class="text-xs text-zinc-500">Geschrieben wird erst nach Bestätigung der Vorschau.</span>
                @endif
                <div class="ml-auto flex gap-2">
                    @if ($isInbox)
                        <button wire:click="preview(false)" class="btn-ghost" @disabled($operation)>Tags speichern …</button>
                        <button wire:click="preview(true)" class="btn-accent" @disabled($operation)>Einordnen …</button>
                    @else
                        <button wire:click="preview(false)" class="btn-ghost" @disabled($operation)>Nur Tags speichern …</button>
                        <button wire:click="preview(true)" class="btn-primary" @disabled($operation)>Speichern …</button>
                    @endif
                </div>
            </footer>
        </section>
    </div>

    {{-- Vorschau --}}
    @if ($plan)
        @php
            $rowsChanged = collect($plan['rows'])->filter(fn ($r) => $r['changed'] || $r['target'] || $r['problems'] || ($r['embed'] ?? false));
            $embed = $plan['embed_cover'] ?? null;
            $conflicts = collect($plan['rows'])->where('conflict', true)->count();
            $show = fn ($v) => is_array($v) ? implode(' ; ', $v) : (is_bool($v) ? ($v ? 'ja' : 'nein') : ($v === '' ? '∅' : $v));
        @endphp
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-6 backdrop-blur-sm" wire:key="preview" x-data="{ downOutside: false }" x-on:keydown.escape.window="$wire.closePreview()"
             x-on:mousedown.self="downOutside = true" x-on:click.self="if (downOutside) $wire.closePreview(); downOutside = false"
             x-on:mouseup="if ($event.target !== $el) downOutside = false">
            <div class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl">
                <div class="flex items-center gap-3 border-b border-zinc-800 px-6 py-4">
                    <h2 class="text-lg font-semibold">Vorschau: {{ $planMove ? ($isInbox ? 'Einordnen' : 'Speichern & verschieben') : 'Tags speichern' }}</h2>
                    @if ($plan['category'])<span class="badge-violet">{{ $plan['category']['name'] }}</span>@endif
                    <button wire:click="closePreview" class="ml-auto text-zinc-400 hover:text-white">✕</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4 text-sm">
                    @foreach ($plan['warnings'] as $w)
                        <div class="mb-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-amber-200">{{ $w }}</div>
                    @endforeach
                    @if ($plan['remove_cover'])
                        <div class="mb-3 rounded-lg border border-red-500/30 bg-red-500/5 p-3">
                            Cover wird aus allen Tracks entfernt.
                            @if ($plan['cover_files'])
                                In den Papierkorb: @foreach ($plan['cover_files'] as $f)<code class="text-red-200">{{ basename($f) }}</code>@if (! $loop->last), @endif @endforeach
                            @endif
                        </div>
                    @endif
                    @if ($plan['cover'])
                        <div class="mb-3 flex items-center gap-3 rounded-lg border border-zinc-800 p-3">
                            <img src="{{ route('cover.tmp', ['f' => basename($plan['cover'])]) }}" class="size-14 rounded object-cover">
                            <span>Neues Cover wird in alle Tracks eingebettet und als <code>cover.jpg</code> im Albumordner abgelegt.</span>
                        </div>
                    @endif
                    @if ($embed)
                        <div class="mb-3 rounded-lg border border-zinc-800 p-3">
                            Cover fehlt in {{ count($embed['tracks']) }} von {{ $embed['total'] }} Tracks und wird dort eingebettet
                            <span class="text-zinc-400">(aus {{ in_array(strtolower(pathinfo($embed['from'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true) ? basename($embed['from']) : '„'.basename($embed['from']).'“' }})</span>.
                        </div>
                    @endif
                    @if ($rowsChanged->isEmpty() && ! $plan['cover'] && ! $plan['remove_cover'] && ! $embed)
                        <p class="py-8 text-center text-zinc-400">Keine Änderungen – alles liegt schon am Regelort.</p>
                    @endif
                    <div class="space-y-2">
                        @foreach ($rowsChanged as $r)
                            <div class="rounded-lg border {{ $r['blocked'] ? 'border-red-500/40 bg-red-500/5' : ($r['conflict'] ? 'border-amber-500/40 bg-amber-500/5' : 'border-zinc-800 bg-zinc-950/40') }} p-3">
                                <div class="truncate font-mono text-xs text-zinc-400" title="{{ $rel($r['path']) }}">{{ $rel($r['path']) }}</div>
                                @if ($r['target'])
                                    <div class="truncate font-mono text-xs text-blue-300" title="{{ $rel($r['target']) }}">→ {{ $rel($r['target']) }}</div>
                                @endif
                                @if ($r['changed'])
                                    <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                        @foreach ($r['changed'] as $k => $v)
                                            <span><span class="text-zinc-500">{{ strtoupper($k) }}:</span> <span class="text-zinc-500 line-through">{{ $show($r['old'][$k] ?? '') }}</span> <span class="text-zinc-100">{{ $show($v) }}</span></span>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($r['embed'] ?? false)
                                    <div class="mt-1 text-xs text-violet-300">+ Cover einbetten</div>
                                @endif
                                @foreach ($r['problems'] as $p)
                                    <div class="mt-1 text-xs {{ $r['blocked'] ? 'text-red-300' : 'text-amber-300' }}">{{ $p }}</div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-4 border-t border-zinc-800 px-6 py-4">
                    @if ($conflicts)
                        <label class="flex items-center gap-2 text-sm text-amber-200">
                            {{ $conflicts }} Konflikt(e):
                            <select wire:model.live="onConflict" class="input-sm w-auto">
                                <option value="best">bessere Version behalten, andere in den Papierkorb</option>
                                <option value="skip">überspringen</option>
                                <option value="number">beide behalten (mit „(2)“)</option>
                            </select>
                        </label>
                    @endif
                    <button wire:click="closePreview" class="btn-ghost ml-auto">Abbrechen</button>
                    <button wire:click="confirm" wire:loading.attr="disabled" class="btn-accent">Bestätigen &amp; ausführen</button>
                </div>
            </div>
        </div>
    @endif

    {{-- „Neuen Künstler erfassen“ als Dialog, nur für den angeklickten Namen --}}
    <livewire:new-artist-modal />
</div>
