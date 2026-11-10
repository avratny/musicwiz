<div class="flex h-full flex-col overflow-hidden">
    <x-page-header title="Künstler" subtitle="Jeder Künstler genau einmal – mit richtiger Schreibweise, Varianten und Bild.">
        @if ($tab === 'directory')
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="Suchen …" class="input w-64">
        @endif
    </x-page-header>
    <div class="flex shrink-0 gap-1 border-b border-zinc-800 px-6 py-2">
        @foreach (['directory' => 'Verzeichnis', 'suggestions' => 'Vorschläge (Schreibweisen)', 'unknown' => 'Neue Künstler erfassen', 'quick' => 'Schnell erfassen'] as $k => $label)
            <button wire:click="$set('tab', '{{ $k }}')" class="{{ $tab === $k ? 'tab-active' : 'tab' }}">{{ $label }}</button>
        @endforeach
    </div>
    @if (session('status'))
        <div class="shrink-0 px-6 pt-3"><div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-200">{{ session('status') }}</div></div>
    @endif
    @if ($splitNew)
        <div class="shrink-0 px-6 pt-3">
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-violet-500/30 bg-violet-500/10 px-4 py-2 text-sm text-violet-200">
                <span>Noch nicht im Künstlerverzeichnis:</span>
                @foreach ($splitNew as $n)
                    <button wire:click="$dispatch('open-new-artist', { name: @js($n) })" class="btn-ghost btn-sm" wire:key="sn{{ md5($n) }}">„{{ $n }}“ erfassen …</button>
                @endforeach
                <button wire:click="$set('splitNew', [])" class="ml-auto opacity-70 hover:opacity-100" title="Später – sie stehen auch unter „Schnell erfassen“">✕</button>
            </div>
        </div>
    @endif

    @if ($tab === 'directory')
        <div class="flex min-h-0 flex-1 overflow-hidden">
            <div class="flex w-80 shrink-0 flex-col border-r border-zinc-800">
                <div class="flex gap-2 border-b border-zinc-800 p-3">
                    <input wire:model="newName" wire:keydown.enter="create" placeholder="Neuer Künstler" class="input-sm">
                    <button wire:click="create" class="btn-primary btn-sm">+</button>
                </div>
                @if ($withoutGenre)
                    <button wire:click="fillDefaultGenres" data-confirm-label="Übernehmen" wire:confirm="Für {{ $withoutGenre }} Künstler ohne Standard-Genre den Vorschlag aus dem Bestand übernehmen? Vorhandene Einträge bleiben unverändert."
                            class="mx-3 mt-2 rounded border border-zinc-700 px-2 py-1 text-xs text-zinc-300 hover:border-zinc-500 hover:text-white">
                        Standard-Genre aus Bestand für {{ $withoutGenre }} Künstler ohne Genre
                    </button>
                @endif
                @if ($withMbid)
                    <div class="mx-3 mt-2 text-xs" @if ($aliasJob['running'] ?? false) wire:poll.3s @endif>
                        @if ($aliasJob['running'] ?? false)
                            <span class="text-blue-300">Schreibweisen von MusicBrainz … {{ $aliasJob['done'] ?? 0 }}/{{ $aliasJob['total'] ?? 0 }}</span>
                        @else
                            <button wire:click="loadAllMusicBrainzAliases" class="w-full rounded border border-zinc-700 px-2 py-1 text-zinc-300 hover:border-zinc-500 hover:text-white"
                                    title="Für {{ $withMbid }} Künstler mit MusicBrainz-ID alle dort bekannten Schreibweisen ins Verzeichnis übernehmen (im Hintergrund, ca. 1 pro Sekunde)">
                                Schreibweisen von MusicBrainz holen ({{ $withMbid }})
                            </button>
                            @if (isset($aliasJob['finished_at']))<p class="mt-1 text-zinc-500">Zuletzt: {{ $aliasJob['added'] }} neue Schreibweise(n){{ ($aliasJob['errors'] ?? 0) ? ', '.$aliasJob['errors'].' Fehler' : '' }}.</p>@endif
                        @endif
                    </div>
                @endif
                @if ($combiTotal)
                    <div class="mx-3 mt-2 text-xs" @if ($combiJob['running'] ?? false) wire:poll.4s @endif>
                        @if ($combiJob['running'] ?? false)
                            <span class="text-blue-300">Kombi-Prüfung … {{ $combiJob['done'] ?? 0 }}/{{ $combiJob['total'] ?? 0 }}</span>
                        @else
                            <button wire:click="startCombiCheck" class="w-full rounded border border-zinc-700 px-2 py-1 text-zinc-300 hover:border-zinc-500 hover:text-white"
                                    title="Für {{ $combiTotal }} Kombi-Namen (&, und, Komma … – im Verzeichnis und noch nicht erfasste) bei Deezer, Spotify und MusicBrainz nachsehen, welche Künstler bei euren Liedern eingetragen sind: ein Act oder eine Zusammenarbeit? Schon geprüfte werden übersprungen.">
                                Kombi-Namen prüfen ({{ $combiTotal }})
                            </button>
                        @endif
                        @if ($collabCount || $onlyCollab)
                            <button wire:click="$toggle('onlyCollab')" class="mt-1 w-full rounded border px-2 py-1 {{ $onlyCollab ? 'border-amber-500/60 bg-amber-500/10 text-amber-200' : 'border-amber-500/30 text-amber-300 hover:border-amber-500/60' }}">
                                {{ $onlyCollab ? 'Alle Einträge zeigen' : '⚠ '.$collabCount.' wahrscheinlich Zusammenarbeit' }}
                            </button>
                        @endif
                    </div>
                @endif
                @if ($splits->isNotEmpty())
                    <div class="mx-3 mt-2 text-xs" x-data="{ open: false }">
                        <button x-on:click="open = ! open" class="text-zinc-400 hover:text-zinc-200"><span x-text="open ? '▾' : '▸'">▸</span> Getrennte Kombinationen ({{ $splits->count() }})</button>
                        <div x-show="open" x-cloak class="mt-1 max-h-48 space-y-0.5 overflow-y-auto">
                            @foreach ($splits as $sp)
                                <div class="flex items-center gap-1 rounded px-1 py-0.5 hover:bg-zinc-800/60" wire:key="sp{{ $sp->id }}">
                                    <span class="min-w-0 flex-1 truncate text-zinc-300" title="{{ $sp->name }} → {{ implode(' · ', $sp->parts) }}">{{ $sp->name }} <span class="text-zinc-500">→ {{ implode(' · ', $sp->parts) }}</span></span>
                                    <button wire:click="deleteSplit({{ $sp->id }})" data-confirm-label="Nicht mehr trennen" wire:confirm="„{{ $sp->name }}“ nicht mehr trennen? Bereits geschriebene ARTISTS-Tags bleiben; das Aufräumen meldet den Namen wieder unter „Kombi mit &“." class="text-zinc-500 hover:text-red-400" title="Nicht mehr trennen">✕</button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    @forelse ($artists as $a)
                        <button wire:click="select({{ $a->id }})" wire:key="ar{{ $a->id }}"
                                class="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-left text-sm transition {{ $selected === $a->id ? 'bg-blue-600/20 ring-1 ring-blue-500/40' : 'hover:bg-zinc-800' }}">
                            @if ($a->imageUrl())
                                <img src="{{ $a->imageUrl() }}" class="size-8 rounded-full object-cover">
                            @else
                                <span class="grid size-8 place-items-center rounded-full bg-zinc-800 text-xs text-zinc-400">{{ mb_substr($a->name, 0, 1) }}</span>
                            @endif
                            <span class="flex-1 truncate" title="{{ $a->name }}">{{ $a->name }}</span>
                            @if (($combis[$a->name]['verdict'] ?? null) === 'collab')<span class="shrink-0 text-xs text-amber-300" title="Kombi-Prüfung: {{ $combis[$a->name]['reason'] }}">⚠</span>@endif
                            @if ($a->aliases_count)<span class="text-xs text-zinc-500">+{{ $a->aliases_count }}</span>@endif
                        </button>
                    @empty
                        <p class="p-4 text-sm text-zinc-500">Noch leer. Unter „Vorschläge“ und „Neue Künstler erfassen“ lassen sich Künstler übernehmen.</p>
                    @endforelse
                    @if ($artistTotal > $artists->count())
                        <div class="flex items-center gap-2 px-2 py-3">
                            <button wire:click="moreArtists" class="btn-ghost btn-sm">Mehr anzeigen</button>
                            <span class="text-xs text-zinc-500">{{ $artists->count() }} von {{ $artistTotal }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                @if ($artist)
                    <div class="flex max-w-4xl gap-8">
                        <div class="w-56 shrink-0 space-y-3">
                            @if ($artist->imageUrl())
                                <img src="{{ $artist->imageUrl() }}" class="aspect-square w-full rounded-2xl object-cover ring-1 ring-zinc-700">
                            @else
                                <div class="grid aspect-square w-full place-items-center rounded-2xl bg-gradient-to-br from-blue-600/30 to-violet-600/30 text-5xl font-semibold">{{ mb_substr($artist->name, 0, 1) }}</div>
                            @endif
                            <button wire:click="findImages" class="btn-ghost w-full"><span wire:loading.remove wire:target="findImages">Bild suchen</span><span wire:loading wire:target="findImages">sucht …</span></button>
                            <label class="btn-ghost w-full cursor-pointer">Eigenes Bild hochladen<input type="file" accept="image/*" wire:model="imageUpload" class="hidden"></label>
                            <div class="flex gap-2">
                                <input type="url" wire:model="imageUrl" wire:keydown.enter="loadImageUrl" placeholder="oder Bild-URL …" class="input-sm text-xs">
                                <button wire:click="loadImageUrl" class="btn-ghost btn-sm shrink-0" wire:loading.attr="disabled" wire:target="loadImageUrl">
                                    <span wire:loading.remove wire:target="loadImageUrl">Laden</span><span wire:loading wire:target="loadImageUrl">…</span>
                                </button>
                            </div>
                            @error('imageUrl')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
                            @if ($artist->image)
                                <div class="flex items-center gap-2">
                                    <p class="min-w-0 flex-1 truncate text-xs text-zinc-500" title="{{ $artist->image }}">{{ $artist->image }}</p>
                                    <button wire:click="removeImage" data-confirm-label="Entfernen" wire:confirm="Bild entfernen? Die Datei wandert in den Papierkorb." class="btn-ghost btn-sm shrink-0 hover:text-red-300">Entfernen</button>
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1 space-y-4">
                            <div><label class="label">Richtiger Name</label><input wire:model="form.name" class="input text-lg font-semibold">
                                @error('form.name')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror</div>
                            @if ($artistTracks)
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($artistTracks as $t)
                                        <div class="flex items-center gap-2 rounded-lg border border-zinc-800 px-2 py-1 text-xs" wire:key="at{{ $t['id'] }}">
                                            <x-play-button :id="$t['id']" :seconds="$t['seconds']" :title="$t['title']" :artist="$t['artist']" :album="$t['album']" size="size-6" />
                                            <span class="max-w-56 truncate" title="{{ $t['title'] }} – {{ $t['album'] }}">{{ $t['title'] }} <span class="text-zinc-500">· {{ $t['album'] }}</span></span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-zinc-500">
                                    Keine Lieder im Bestand unter diesem Namen oder seinen Schreibweisen.
                                    @if ($inboxTracks){{ $inboxTracks }} {{ $inboxTracks === 1 ? 'Lied liegt' : 'Lieder liegen' }} noch in der Inbox – sie erscheinen hier nach dem Einordnen.@endif
                                </p>
                            @endif
                            <div class="grid grid-cols-2 gap-3">
                                <div><label class="label">MusicBrainz-ID</label><input wire:model.blur="form.mbid" class="input font-mono text-xs"></div>
                                <div><label class="label">Discogs-ID</label><input wire:model.blur="form.discogs_id" class="input font-mono text-xs"></div>
                            </div>
                            <div><label class="label">Standard-Genre (Vorschlag für neue Alben – wird sofort gespeichert)</label>
                                <select wire:model.live="form.default_genre" class="input">
                                    <option value="">—</option>
                                    @foreach ($genreOptions as $o)<option value="{{ $o['name'] }}">{{ $o['label'] }}</option>@endforeach
                                </select>
                                <div class="mt-1 flex items-start gap-2">
                                    <button wire:click="lookupGenres" class="btn-ghost btn-sm shrink-0" title="Bestand, MusicBrainz (mit ID) und Discogs (mit ID) befragen">
                                        <span wire:loading.remove wire:target="lookupGenres">Online ermitteln</span><span wire:loading wire:target="lookupGenres">sucht …</span>
                                    </button>
                                    @include('livewire.partials.genre-proposals', ['proposals' => $genreProposals, 'current' => $form['default_genre'] ?? null, 'model' => 'form.default_genre'])
                                </div>
                                @if ($genreProposals)
                                @elseif ($genreSuggestion && $genreSuggestion['genre'] !== ($form['default_genre'] ?? null))
                                    <button wire:click="useSuggestedGenre" class="mt-1 text-xs text-blue-400 hover:underline">
                                        Vorschlag aus Bestand: {{ $genreSuggestion['genre'] }} ({{ $genreSuggestion['count'] }} von {{ $genreSuggestion['total'] }} Liedern) übernehmen
                                    </button>
                                @elseif ($genreSuggestion)
                                    <p class="mt-1 text-xs text-zinc-500">passt zum Bestand ({{ $genreSuggestion['count'] }} von {{ $genreSuggestion['total'] }} Liedern)</p>
                                @else
                                    <p class="mt-1 text-xs text-zinc-500">Im Bestand gibt es noch keine Lieder mit Listen-Genre.</p>
                                @endif
                            </div>
                            @if ($audiodb)
                                <div class="rounded-xl border border-zinc-800 p-3 text-sm" x-data="{ open: false }">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400">
                                        <span class="font-medium text-zinc-200">TheAudioDB</span>
                                        @if ($audiodb['genre'] || $audiodb['style'])<span>{{ collect([$audiodb['genre'], $audiodb['style']])->filter()->unique()->implode(' / ') }}</span>@endif
                                        @if ($audiodb['members'])<span>{{ $audiodb['members'] === 1 ? 'Solo' : $audiodb['members'].' Mitglieder' }}</span>@endif
                                        @if ($audiodb['country'])<span>{{ $audiodb['country'] }}</span>@endif
                                        @if ($audiodb['biography'])<button x-on:click="open = ! open" class="ml-auto text-zinc-400 hover:text-white" x-text="open ? 'Biografie ausblenden' : 'Biografie{{ $audiodb['biography_lang'] === 'en' ? ' (englisch)' : '' }}'"></button>@endif
                                    </div>
                                    @if ($audiodb['biography'])<p x-show="open" x-cloak class="mt-2 max-h-64 overflow-y-auto text-xs whitespace-pre-line text-zinc-300">{{ $audiodb['biography'] }}</p>@endif
                                </div>
                            @endif
                            @if ($combiApplies)
                                <div class="rounded-xl border {{ ($combi['verdict'] ?? null) === 'collab' ? 'border-amber-500/30 bg-amber-500/5' : 'border-zinc-800' }} p-3 text-sm">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-zinc-200">Kombi-Prüfung</span>
                                        @if ($combi)<x-combi-verdict :combi="$combi" class="min-w-0 flex-1" />@else<span class="flex-1 text-xs text-zinc-500">noch nicht geprüft</span>@endif
                                        <button wire:click="checkCombiNow" class="btn-ghost btn-sm shrink-0"><span wire:loading.remove wire:target="checkCombiNow">{{ $combi ? 'neu prüfen' : 'jetzt prüfen' }}</span><span wire:loading wire:target="checkCombiNow">prüft …</span></button>
                                    </div>
                                    @if ($combi && $combi['evidence'])
                                        <table class="mt-2 w-full text-xs">
                                            @foreach ($combi['evidence'] as $e)
                                                <tr class="align-top">
                                                    <td class="w-24 pr-2 text-zinc-500">{{ $e['source'] }}</td>
                                                    <td class="max-w-56 truncate pr-2 text-zinc-400" title="{{ $e['title'] }}">{{ $e['title'] }}</td>
                                                    <td class="pr-2 {{ $e['vote'] === 'collab' ? 'text-amber-300' : ($e['vote'] === 'act' ? 'text-emerald-300' : 'text-zinc-500') }}">{{ implode(' + ', $e['credited']) }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif
                                    @if ($combi)
                                        <p class="mt-1 text-xs text-zinc-500">Bestand: {{ $combi['local']['own_albums'] }} eigene(s) Album/Alben unter dem Namen{{ $combi['local']['parts'] ? ' · solo: '.collect($combi['local']['parts'])->map(fn ($n, $p) => $p.' '.$n)->implode(', ') : '' }} · geprüft {{ \Illuminate\Support\Carbon::parse($combi['checked_at'])->diffForHumans() }}</p>
                                    @endif
                                </div>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                <button wire:click="saveArtist" class="btn-primary">Speichern</button>
                                <button wire:click="lookupMusicBrainz" class="btn-ghost"><span wire:loading.remove wire:target="lookupMusicBrainz">Bei MusicBrainz nachschlagen</span><span wire:loading wire:target="lookupMusicBrainz">sucht …</span></button>
                                <button wire:click="lookupDiscogs" class="btn-ghost">Discogs</button>
                                @unless ($artist->base_name)
                                    <button wire:click="$toggle('mergeOpen')" class="btn-ghost {{ $mergeOpen ? 'border-blue-500/60 text-blue-200' : '' }}" title="Andere Einträge sind nur andere Schreibweisen dieses Künstlers">Ist derselbe wie …</button>
                                @endunless
                                @if (\App\Support\ArtistNames::looksCollaboration($artist->name))
                                    <button wire:click="openSplitInto" class="btn-ghost {{ $splitInto ? 'border-blue-500/60 text-blue-200' : '' }}" title="Kein fester Act, sondern eine Zusammenarbeit – künftig in die einzelnen Künstler trennen">In Einzelkünstler trennen …</button>
                                @endif
                                <button wire:click="deleteArtist" data-confirm-label="Löschen" wire:confirm="Künstler aus dem Verzeichnis löschen? (Dateien bleiben unverändert)" class="btn-ghost ml-auto hover:text-red-400">Löschen</button>
                            </div>
                            @if ($splitInto)
                                <div class="rounded-xl border border-blue-500/30 bg-blue-500/5 p-3">
                                    <div class="text-sm text-zinc-300">„{{ $artist->name }}“ ist eine Zusammenarbeit? Dann wird der Eintrag aus dem Verzeichnis genommen und künftig getrennt – auch seine Kombi-Schreibweisen. Die Tags (ARTISTS) der Titel werden vorgemerkt, neue Einzelnamen kannst du danach erfassen.</div>
                                    <div class="mt-2 space-y-1.5">
                                        @foreach ($splitInto['parts'] as $i => $p)
                                            @php $h = $splitIntoHints[$i] ?? null; @endphp
                                            <div wire:key="sip{{ $i }}">
                                                <div class="flex items-center gap-2">
                                                    <input wire:model.live.debounce.400ms="splitInto.parts.{{ $i }}" class="input-sm w-72">
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
                                    @if ($splitInto['error'])<p class="mt-1 text-sm text-amber-300">{{ $splitInto['error'] }}</p>@endif
                                    <div class="mt-2 flex items-center gap-2">
                                        <button wire:click="saveSplitInto" data-confirm-label="Trennen" wire:confirm="„{{ $artist->name }}“ aus dem Verzeichnis nehmen und künftig in die Einzelkünstler trennen? Die Dateien ändern sich erst mit „Änderungen schreiben“." class="btn-primary btn-sm">Trennen</button>
                                        <button wire:click="$set('splitInto', null)" class="btn-ghost btn-sm">Abbrechen</button>
                                    </div>
                                </div>
                            @endif
                            @if ($mergeOpen)
                                <div class="rounded-xl border border-blue-500/30 bg-blue-500/5 p-3">
                                    <div class="text-sm text-zinc-300">Welche Einträge sind in Wahrheit <strong>„{{ $artist->name }}“</strong>? Sie werden zu Schreibweisen dieses Künstlers; Varianten, IDs, Genre und Bild werden übernommen, wo hier noch nichts steht.</div>
                                    <input type="search" wire:model.live.debounce.300ms="mergeQ" placeholder="Anderen Eintrag suchen …" class="input-sm mt-2 w-72">
                                    <div class="mt-2 max-h-64 space-y-0.5 overflow-y-auto">
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
                                    <div class="mt-2 flex items-center gap-2">
                                        <button wire:click="merge" @disabled(! $mergeIds) data-confirm-label="Zusammenführen" wire:confirm="Die angehakten Einträge in „{{ $artist->name }}“ aufgehen lassen? Ihre Namen werden Schreibweisen von „{{ $artist->name }}“; die Dateien bleiben vorerst unverändert." class="btn-primary btn-sm">Zusammenführen ({{ count($mergeIds) }})</button>
                                        <button wire:click="$set('mergeOpen', false)" class="btn-ghost btn-sm">Abbrechen</button>
                                    </div>
                                </div>
                            @endif
                            @if ($lookupError)<p class="text-sm text-amber-300">{{ $lookupError }}</p>@endif

                            @if ($mbResults)
                                <div class="text-xs font-medium tracking-wide text-zinc-400 uppercase">Treffer bei MusicBrainz – Klick speichert die MusicBrainz-ID</div>
                                <div class="card divide-y divide-zinc-800">
                                    @foreach ($mbResults as $i => $r)
                                        <button wire:click="useMusicBrainz({{ $i }})" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-zinc-800">
                                            <span class="font-medium">{{ $r['name'] }}</span>
                                            <span class="text-zinc-500">{{ $r['disambiguation'] }} {{ $r['country'] }}</span>
                                            <span class="ml-auto text-xs text-zinc-500">{{ $r['score'] }}%</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            @if ($discogsResults)
                                <div class="text-xs font-medium tracking-wide text-zinc-400 uppercase">{{ $mbResults ? '' : 'Keine Treffer bei MusicBrainz – ' }}Treffer bei Discogs – Klick speichert die Discogs-ID</div>
                                <div class="card divide-y divide-zinc-800">
                                    @foreach ($discogsResults as $i => $r)
                                        <button wire:click="useDiscogs({{ $i }})" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-zinc-800">
                                            @if ($r['image'])<img src="{{ $r['image'] }}" class="size-8 rounded object-cover">@endif
                                            <span class="font-medium">{{ $r['name'] }}</span><span class="text-xs text-zinc-500">{{ $r['raw'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            @if ($imageCandidates)
                                <div class="grid grid-cols-4 gap-3">
                                    @foreach ($imageCandidates as $i => $c)
                                        <button wire:click="useImage({{ $i }})" class="group text-left">
                                            <img src="{{ $c['thumb'] }}" class="aspect-square w-full rounded-lg object-cover ring-1 ring-zinc-700 group-hover:ring-2 group-hover:ring-blue-500">
                                            <span class="mt-1 block truncate text-xs text-zinc-400" title="{{ $c['source'] }} {{ $c['size'] }}">{{ $c['source'] }} {{ $c['size'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <div>
                                <label class="label">Bekannte Varianten</label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($artist->aliases as $al)
                                        <span class="badge-gray gap-1" @if ($al->source === 'musicbrainz') title="Schreibweise laut MusicBrainz" @endif>
                                            {{ $al->alias }}@if ($al->source === 'musicbrainz')<span class="text-[10px] text-emerald-300/80">MB</span>@endif
                                            <button wire:click="removeAlias({{ $al->id }})" class="text-zinc-500 hover:text-red-400" title="Löschen – wird gemerkt, „Von MusicBrainz holen“ trägt sie nicht wieder ein">✕</button>
                                        </span>
                                    @endforeach
                                </div>
                                <div class="mt-2 flex gap-2"><input wire:model="newAlias" wire:keydown.enter="addAlias" placeholder="Variante hinzufügen" class="input-sm w-72"><button wire:click="addAlias" class="btn-ghost btn-sm">+</button>
                                    @if ($artist->mbid)
                                        <button wire:click="loadMusicBrainzAliases" class="btn-ghost btn-sm" title="Alle bei MusicBrainz bekannten Schreibweisen übernehmen (außer bürgerlichem Namen)">
                                            <span wire:loading.remove wire:target="loadMusicBrainzAliases">Von MusicBrainz holen</span><span wire:loading wire:target="loadMusicBrainzAliases">…</span>
                                        </button>
                                    @endif
                                </div>
                                @error('newAlias')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                @if ($artist->rejectedAliases->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                                        <span title="Diese Schreibweisen hast du gelöscht – MusicBrainz trägt sie nicht wieder ein">Gelöscht, wird nicht wieder geholt:</span>
                                        @foreach ($artist->rejectedAliases as $r)
                                            <span class="inline-flex items-center gap-1 rounded border border-zinc-800 px-1.5 py-0.5 text-zinc-500 line-through decoration-zinc-600">{{ $r->alias }}<button wire:click="allowAlias({{ $r->id }})" class="text-zinc-500 no-underline hover:text-blue-300" title="Wieder zulassen">↺</button></span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @include('livewire.partials.homonyms')
                @else
                    <div class="grid h-full place-items-center text-zinc-500">Künstler wählen</div>
                @endif
            </div>
        </div>
    @elseif ($tab === 'suggestions')
        @php
            $pct = fn ($p) => $p === null ? '' : round($p * 100).' %';
            $tone = fn ($p) => $p >= 0.8 ? 'badge-green' : ($p >= 0.4 ? 'badge-amber' : 'badge-red');
        @endphp
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden" @if ($researching) wire:poll.2s @endif>
            <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-zinc-800 px-6 py-3 text-sm">
                <span class="text-zinc-400">Zeigen:</span>
                @foreach (['' => 'Alle', 'sure' => 'Sicher (≥ 80 %)', 'unsure' => 'Unsicher', 'different' => 'Wohl verschiedene'] as $k => $label)
                    <button wire:click="$set('filter', '{{ $k }}')" class="{{ $filter === $k ? 'tab-active' : 'tab' }}">{{ $label }}</button>
                @endforeach
                <span class="ml-4 text-zinc-400">Sortieren:</span>
                <select wire:model.live="sort" class="input-sm w-48">
                    <option value="count">nach Häufigkeit</option>
                    <option value="sure">sicherste zuerst</option>
                    <option value="unsure">unsicherste zuerst</option>
                </select>
                <button wire:click="researchAll" class="btn-ghost btn-sm ml-auto" title="MusicBrainz: höchstens 30 Gruppen, ca. 1 Abfrage pro Sekunde, im Hintergrund">
                    Angezeigte recherchieren
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                <p class="mb-4 text-sm text-zinc-400">
                    Wahrscheinlichkeit „dieselbe Person“ aus Schreibweise, gemeinsamen Liedern, Alben, Mitwirkenden und Genres – mit „Recherchieren“ zusätzlich aus MusicBrainz.
                    <strong class="text-zinc-300">Zusammenführen</strong>: alle Namen sind Schreibweisen der gewählten (blau umrandeten) Variante – ausgeklammerte (✕) bleiben eigene Künstler.
                    <strong class="text-zinc-300">Verschiedene Künstler</strong>: jeder Name bekommt einen eigenen Eintrag. Varianten unter 40 % sind vorab ausgeklammert (↺ nimmt sie wieder auf). Gespeichert wird erst per Klick.
                </p>
                <div class="space-y-3">
                    @forelse ($groups as $g)
                        @php
                            $a = $g['analysis'];
                            $research = $a['research'];
                            $chosen = ($choice[$g['key']] ?? '') ?: $a['recommendation'];
                        @endphp
                        <div class="card p-4" wire:key="sg{{ $g['key'] }}">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="{{ $tone($a['confidence']) }}" title="geringste Wahrscheinlichkeit in der Gruppe">{{ $pct($a['confidence']) }}</span>
                                <span class="text-sm text-zinc-400">Empfehlung:</span>
                                <span class="font-semibold text-blue-200">{{ $a['recommendation'] }}</span>
                                @if ($a['recommendation_reasons'])
                                    <span class="text-xs text-zinc-500">({{ implode(' · ', $a['recommendation_reasons']) }})</span>
                                @endif
                                @if ($g['known'])<span class="badge-violet">im Verzeichnis: {{ $g['known'] }}</span>@endif
                                @php
                                    $conf = $a['confidence'];
                                    $verdict = $conf >= 0.8 ? ['Wahrscheinlich dieselbe Person – „Zusammenführen“.', 'text-emerald-300']
                                        : ($conf >= 0.4 ? ['Unsicher – Lieder anhören oder „Recherchieren“, dann entscheiden.', 'text-amber-300']
                                        : ['Wahrscheinlich verschiedene Künstler – „Verschiedene Künstler“.', 'text-red-300']);
                                @endphp
                                <span class="text-sm font-medium {{ $verdict[1] }}">{{ $verdict[0] }}</span>
                                @if ($a['mb_name'] && ! isset($a['variants'][$a['mb_name']]) && $chosen !== $a['mb_name'])
                                    <button wire:click="$set('choice.{{ $g['key'] }}', @js($a['mb_name']))" class="badge-blue hover:bg-blue-500/30" title="Schreibweise von MusicBrainz übernehmen">MusicBrainz: {{ $a['mb_name'] }}</button>
                                @endif
                                <div class="ml-auto flex items-center gap-2">
                                    @if ($research['running'] ?? false)
                                        <span class="flex items-center gap-2 text-xs text-blue-300"><span class="size-2 animate-pulse rounded-full bg-blue-400"></span>recherchiert …</span>
                                    @else
                                        <button wire:click="research(@js($g['key']))" class="btn-ghost btn-sm">{{ $research ? 'Neu recherchieren' : 'Recherchieren' }}</button>
                                    @endif
                                    <input wire:model.live.debounce.400ms="choice.{{ $g['key'] }}" placeholder="{{ $a['recommendation'] }}" class="input-sm w-56">
                                    <button wire:click="separateGroup(@js($g['key']))" class="{{ $conf < 0.4 ? 'btn-primary' : 'btn-ghost' }} btn-sm" title="Keine Schreibweisen derselben Person – jeder Name bekommt einen eigenen Eintrag im Verzeichnis">Verschiedene Künstler</button>
                                    <button wire:click="resolveGroup(@js($g['key']))" class="{{ $conf < 0.4 ? 'btn-ghost' : 'btn-primary' }} btn-sm" title="Alle nicht ausgeklammerten Namen als Schreibweisen von „{{ $chosen }}“ speichern">Zusammenführen als „{{ $chosen }}“</button>
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-[repeat(auto-fill,minmax(20rem,1fr))] gap-2">
                                @foreach ($a['variants'] as $name => $v)
                                    @php $excluded = in_array($name, $g['excluded'], true); @endphp
                                    <div class="rounded-lg border p-2.5 text-sm transition {{ $excluded ? 'border-zinc-800 bg-zinc-950/40 opacity-60' : ($chosen === $name ? 'border-blue-500 bg-blue-500/10' : 'border-zinc-700') }}">
                                        <div class="flex items-center gap-2">
                                            <button wire:click="$set('choice.{{ $g['key'] }}', @js($name))" @disabled($excluded) class="min-w-0 truncate text-left font-medium {{ $excluded ? 'line-through' : 'hover:text-blue-200' }}" title="{{ $name }} als richtige Schreibweise wählen">{{ $name }}</button>
                                            <span class="text-xs text-zinc-500">{{ $v['count'] }} Dateien</span>
                                            @if ($v['is_main'])
                                                <span class="badge-gray">Bezug</span>
                                            @else
                                                <span class="{{ $tone($v['probability']) }}" title="Wahrscheinlichkeit, dass es dieselbe Person wie „{{ $a['main'] }}“ ist">{{ $pct($v['probability']) }} gleich</span>
                                            @endif
                                            <button wire:click="toggleExclude(@js($g['key']), @js($name), {{ $excluded ? 'true' : 'false' }})"
                                                    title="{{ $excluded ? 'wieder aufnehmen' : 'gehört nicht dazu' }}" class="ml-auto px-1 text-xs text-zinc-500 hover:text-red-300">{{ $excluded ? '↺' : '✕' }}</button>
                                        </div>
                                        @if ($research && ! ($research['running'] ?? false))
                                            @php $hit = $research['artists'][$name] ?? null; @endphp
                                            @php $label = $hit ? $hit['name'].($hit['disambiguation'] ? ' – '.$hit['disambiguation'] : '').($hit['country'] ? ' ['.$hit['country'].']' : '') : null; @endphp
                                            @if ($hit && ($hit['exact'] ?? true))
                                                <div class="mt-1 truncate text-xs text-emerald-300/80" title="{{ $hit['name'].' '.$hit['disambiguation'].' '.$hit['mbid'] }}">MusicBrainz: {{ $label }}</div>
                                            @else
                                                {{-- nur ein ähnlicher Name (z. B. "Herbert Tauscher" für "Tauscher") ist kein Treffer --}}
                                                <div class="mt-1 truncate text-xs text-zinc-500" title="{{ $label ? 'ähnlichster Name: '.$label : '' }}">MusicBrainz: kein Künstler „{{ $name }}“{{ $label ? ' (nur ähnlich: '.$label.')' : '' }}</div>
                                            @endif
                                        @endif
                                        @if ($v['reasons'])
                                            <ul class="mt-1.5 space-y-0.5 text-xs">
                                                @foreach ($v['reasons'] as [$sign, $text])
                                                    <li class="{{ $sign === '+' ? 'text-emerald-300/90' : ($sign === '-' ? 'text-red-300/90' : 'text-zinc-400') }}">{{ $sign === '~' ? '•' : $sign }} {{ $text }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if ($v['tracks'])
                                            @php
                                                $exId = $g['key'].'|'.$name;
                                                $first = $v['tracks'][0];
                                            @endphp
                                            <div class="mt-2 flex items-center gap-2">
                                                <x-play-button :id="$first['id']" :seconds="$first['seconds'] ?? null" :title="$first['title']" :artist="$first['artist']" :album="$first['album']" :cover="$covers[$first['album_id']] ?? null" start="0.3" size="size-6" />
                                                <button wire:click="toggleExpand(@js($exId))" class="min-w-0 flex-1 truncate text-left text-xs text-zinc-400 hover:text-zinc-200" title="{{ implode("\n", $v['samples']) }}">
                                                    {{ in_array($exId, $expanded, true) ? '▾' : '▸' }} {{ count($v['tracks']) }} Lieder anhören · {{ implode(' · ', array_slice($v['samples'], 0, 3)) }}
                                                </button>
                                            </div>
                                            @if (in_array($exId, $expanded, true))
                                                <ul class="mt-2 space-y-1 border-t border-zinc-800 pt-2">
                                                    @foreach ($v['tracks'] as $t)
                                                        <li class="flex items-center gap-2 text-xs" wire:key="t{{ $t['id'] }}">
                                                            <x-play-button :id="$t['id']" :seconds="$t['seconds'] ?? null" :title="$t['title']" :artist="$t['artist']" :album="$t['album']" :cover="$covers[$t['album_id']] ?? null" start="0.3" size="size-6" />
                                                            <div class="min-w-0 flex-1">
                                                                <div class="truncate text-zinc-200" title="{{ $t['title'] }}">{{ $t['title'] }}</div>
                                                                @php $sub = implode(' · ', array_filter([$t['artist'], $t['album']])); @endphp
                                                                <div class="truncate text-zinc-500" title="{{ $sub }}">{{ $sub }}</div>
                                                            </div>
                                                            @if ($t['album_id'])<a href="{{ route('library.album', $t['album_id']) }}" wire:navigate class="text-zinc-500 hover:text-blue-300" title="Album öffnen">↗</a>@endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if (! empty($research['error']))
                                <p class="mt-2 text-xs text-amber-300">Recherche: {{ $research['error'] }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-zinc-500">Keine passenden Vorschläge (erst scannen oder Filter ändern).</p>
                    @endforelse
                </div>
            </div>
        </div>
    @elseif ($tab === 'suggestions')
        <div class="min-h-0 flex-1 overflow-y-auto p-6">
            <p class="mb-4 max-w-4xl text-sm text-zinc-400">Kandidaten für dieselbe Person (Vergleichsschlüssel und Ähnlichkeitssuche). Vorschlag ist die häufigste Variante – die richtige Schreibweise entscheidest du.</p>
            <div class="max-w-4xl space-y-2">
                @forelse ($groups as $g)
                    <div class="card flex items-center gap-4 p-3" wire:key="sg{{ $g['key'] }}">
                        <div class="flex min-w-0 flex-1 flex-wrap gap-2">
                            @foreach ($g['names'] as $name => $n)
                                @php $excluded = in_array($name, $exclude[$g['key']] ?? [], true); @endphp
                                <span class="inline-flex items-center overflow-hidden rounded-lg border text-sm transition {{ $excluded ? 'border-zinc-800 text-zinc-600 line-through' : (($choice[$g['key']] ?? $g['suggestion']) === $name ? 'border-blue-500 bg-blue-500/15 text-blue-100' : 'border-zinc-700 text-zinc-300 hover:border-zinc-500') }}">
                                    <button wire:click="$set('choice.{{ $g['key'] }}', @js($name))" class="px-2.5 py-1" @disabled($excluded)>{{ $name }} <span class="text-xs text-zinc-500">{{ $n }}</span></button>
                                    <button wire:click="toggleExclude(@js($g['key']), @js($name))" title="{{ $excluded ? 'wieder aufnehmen' : 'gehört nicht dazu' }}" class="border-l border-zinc-700 px-1.5 py-1 text-xs text-zinc-500 hover:text-red-300">{{ $excluded ? '↺' : '✕' }}</button>
                                </span>
                            @endforeach
                        </div>
                        @if ($g['known'])<span class="badge-violet">im Verzeichnis: {{ $g['known'] }}</span>@endif
                        <input wire:model="choice.{{ $g['key'] }}" placeholder="{{ $g['suggestion'] }}" class="input-sm w-48">
                        <button wire:click="resolveGroup(@js($g['key']))" class="btn-primary btn-sm">Übernehmen</button>
                    </div>
                @empty
                    <p class="text-zinc-500">Keine uneinheitlichen Schreibweisen gefunden (erst scannen).</p>
                @endforelse
            </div>
        </div>
    @elseif ($tab === 'quick')
        @include('livewire.partials.quick-artists')
    @else
        @include('livewire.partials.new-artist-wizard')
    @endif
    {{-- „Neuen Künstler erfassen“ als Dialog (Schnell erfassen, nach dem Trennen) – die Liste bleibt erhalten --}}
    <livewire:new-artist-modal />
</div>
