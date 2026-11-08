{{-- Gleichnamige Künstler: aufteilen und Alben zuordnen --}}
<div class="mt-8 max-w-5xl border-t border-zinc-800 pt-6">
    <div class="flex items-center gap-3">
        <h3 class="font-semibold">Gleichnamige Künstler</h3>
        @if ($homonym)
            <span class="badge-violet">„{{ $homonym['base'] }}“ gibt es {{ $homonym['persons']->count() }}×</span>
        @endif
        <button wire:click="openSplit" class="btn-ghost btn-sm ml-auto">{{ $homonym ? 'Person hinzufügen …' : 'Diesen Namen gibt es mehrfach …' }}</button>
    </div>
    <p class="mt-1 text-xs text-zinc-500">
        Navidrome unterscheidet Künstler nur am Namen. Deshalb bekommt jede Person einen Zusatz in <code>ARTISTS</code> (z. B. „{{ $artist->baseName() }} [DE]“);
        der Anzeigetext bleibt „{{ $artist->baseName() }}“. Bild: <code>{{ $artist->baseName() }} [Zusatz].jpg</code>.
    </p>
    @if ($splitError)<p class="mt-2 text-sm text-amber-300">{{ $splitError }}</p>@endif

    {{-- Aufteilen --}}
    @if ($splitOpen)
        <div class="card mt-3 space-y-3 p-4">
            <div class="flex items-center gap-2">
                <button wire:click="loadSplitCandidates" class="btn-ghost btn-sm">
                    <span wire:loading.remove wire:target="loadSplitCandidates">Bei MusicBrainz nachsehen &amp; Zusätze vorschlagen</span>
                    <span wire:loading wire:target="loadSplitCandidates">sucht …</span>
                </button>
                <button wire:click="addSplitPerson" class="btn-ghost btn-sm">+ Person von Hand</button>
                <button wire:click="$set('splitOpen', false)" class="ml-auto text-sm text-zinc-500 hover:text-white">Abbrechen</button>
            </div>
            <table class="w-full text-sm">
                <thead><tr>
                    <th class="th w-10"></th>
                    @if (! $artist->base_name)<th class="th w-24" title="Welche Person ist der bisherige Eintrag (Bild, Varianten, Genre)?">bisher</th>@endif
                    <th class="th w-56">Zusatz</th><th class="th">Ergibt</th><th class="th">MusicBrainz</th>
                </tr></thead>
                <tbody>
                @foreach ($splitPersons as $i => $p)
                    <tr wire:key="sp{{ $i }}" class="{{ empty($p['include']) ? 'opacity-50' : '' }}">
                        <td class="td"><input type="checkbox" wire:model.live="splitPersons.{{ $i }}.include" class="rounded border-zinc-600 bg-zinc-800"></td>
                        @if (! $artist->base_name)
                            <td class="td"><input type="radio" wire:model.live="splitExisting" value="{{ $i }}" class="border-zinc-600 bg-zinc-800"></td>
                        @endif
                        <td class="td"><input wire:model.live.debounce.300ms="splitPersons.{{ $i }}.suffix" placeholder="z. B. DE, DJ, Band" class="input-sm"></td>
                        <td class="td font-medium text-blue-200">{{ \App\Models\Artist::fullName($artist->baseName(), $p['suffix'] ?: '…') }}</td>
                        <td class="td text-xs text-zinc-400">{{ $p['label'] }} @if ($p['mbid'])<span class="text-zinc-600">{{ \Illuminate\Support\Str::limit($p['mbid'], 8, '…') }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="flex items-center gap-3">
                <span class="text-xs text-zinc-500">Zusatz-Vorschlag: Land, wenn eindeutig – sonst Kurzbeschreibung von MusicBrainz. Kurz halten, er steht im Künstlernamen.</span>
                <button wire:click="doSplit" wire:confirm="Personen anlegen? Dateien werden dabei noch nicht verändert." class="btn-primary ml-auto">{{ $artist->base_name ? 'Personen hinzufügen' : 'Aufteilen' }}</button>
            </div>
        </div>
    @endif

    {{-- Personen + Zuordnung --}}
    @if ($homonym)
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($homonym['persons'] as $p)
                <div class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-sm {{ $p->id === $artist->id ? 'border-blue-500 bg-blue-500/10' : 'border-zinc-700' }}" wire:key="hp{{ $p->id }}">
                    <button wire:click="select({{ $p->id }})" class="font-medium hover:text-blue-200">{{ $p->name }}</button>
                    <span class="text-xs text-zinc-500">{{ $p->disambiguation }}</span>
                    <input value="{{ $p->suffix }}" wire:change="changeSuffix({{ $p->id }}, $event.target.value)" title="Zusatz ändern (Bild wird mit umbenannt)" class="input-sm w-20 text-xs">
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            <div class="flex items-center gap-3">
                <div class="font-medium">Alben zuordnen</div>
                <span class="text-xs text-zinc-500">{{ collect($homonym['albums'])->whereNull('assigned')->count() }} von {{ count($homonym['albums']) }} noch ohne Zuordnung</span>
                <label class="ml-auto flex items-center gap-2 text-sm text-zinc-300">
                    <input type="checkbox" wire:model="assignMove" class="rounded border-zinc-600 bg-zinc-800">
                    Ordner im Bestand gleich nach Regel umbenennen
                </label>
                <button wire:click="previewAssign" class="btn-primary btn-sm">Zuordnung schreiben …</button>
            </div>
            <table class="mt-2 w-full text-sm">
                <thead><tr><th class="th w-10"></th><th class="th">Album</th><th class="th w-40">Genre</th><th class="th w-64">Person</th><th class="th">Vorschlag</th></tr></thead>
                <tbody>
                @foreach ($homonym['albums'] as $row)
                    @php $a = $row['album']; $s = $row['sample']; @endphp
                    <tr wire:key="ha{{ $a->id }}" class="hover:bg-zinc-900/60">
                        <td class="td">@if ($s)<x-play-button :id="$s->id" :seconds="$s->duration" :title="$s->title" :artist="$s->artist" :album="$s->album" :cover="$a->coverUrl(120)" start="0.3" />@endif</td>
                        <td class="td">
                            <a href="{{ $a->editUrl() }}" wire:navigate class="font-medium hover:text-blue-200" title="{{ $a->dir }}">{{ $a->title }}</a>
                            <div class="text-xs text-zinc-500">{{ $row['tracks'] }} Track(s) · {{ $a->location === 'inbox' ? 'Inbox' : $a->category }} @if ($row['mixed'])· <span class="text-amber-300">gemischt zugeordnet</span>@endif</div>
                        </td>
                        <td class="td text-xs text-zinc-400">{{ implode(', ', $row['genres']) }}</td>
                        <td class="td">
                            <select wire:model.live="assign.{{ $a->id }}" class="input-sm {{ $row['assigned'] ? '' : 'border-amber-500/50' }}">
                                <option value="">— nicht zugeordnet —</option>
                                @foreach ($homonym['persons'] as $p)<option value="{{ $p->name }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </td>
                        <td class="td text-xs">
                            @if ($row['assigned'])<span class="text-emerald-300/80">zugeordnet</span>@endif
                            @if (isset($mbSuggest[$a->id]))
                                <span class="{{ $mbSuggest[$a->id][0] ? 'text-blue-300' : 'text-zinc-500' }}">{{ $mbSuggest[$a->id][1] }}</span>
                            @elseif ($row['suggestion'])
                                <span class="text-blue-300">{{ $row['reason'] }}</span>
                            @endif
                            @if (! $row['assigned'] && ! isset($mbSuggest[$a->id]))
                                <button wire:click="checkMusicBrainz({{ $a->id }})" class="ml-1 text-zinc-400 hover:text-white hover:underline">
                                    <span wire:loading.remove wire:target="checkMusicBrainz({{ $a->id }})">MusicBrainz prüfen</span><span wire:loading wire:target="checkMusicBrainz({{ $a->id }})">prüft …</span>
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($assignPreview)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-6 backdrop-blur-sm">
            <div class="flex max-h-full w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl">
                <div class="flex items-center border-b border-zinc-800 px-6 py-4">
                    <h2 class="text-lg font-semibold">Vorschau: Zuordnung schreiben · {{ $assignPreview['steps'] }} Schritte</h2>
                    <button wire:click="$set('assignPreview', null)" class="ml-auto text-zinc-400 hover:text-white">✕</button>
                </div>
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto px-6 py-4 text-sm">
                    @forelse ($assignPreview['rows'] as $r)
                        <div class="rounded-lg border border-zinc-800 p-3">
                            <div><span class="font-medium">{{ $r['album'] }}</span> → <span class="text-blue-200">{{ $r['person'] }}</span> <span class="text-xs text-zinc-500">({{ $r['changes'] }} Datei(en) mit Änderungen)</span></div>
                            @if ($r['target'])<div class="truncate font-mono text-xs text-blue-300" title="{{ $r['target'] }}">→ {{ $r['target'] }}</div>@endif
                            @foreach ($r['problems'] as $pr)<div class="text-xs text-amber-300">{{ $pr }}</div>@endforeach
                        </div>
                    @empty
                        <p class="text-zinc-500">Nichts ausgewählt.</p>
                    @endforelse
                    <p class="text-xs text-zinc-500">Geschrieben wird <code>ARTISTS</code>/<code>ALBUMARTISTS</code> mit Zusatz; der Anzeigetext bleibt. Rückgängig im Verlauf.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-zinc-800 px-6 py-4">
                    <button wire:click="$set('assignPreview', null)" class="btn-ghost">Abbrechen</button>
                    <button wire:click="confirmAssign" class="btn-accent" @disabled(! $assignPreview['steps'])>Bestätigen &amp; ausführen</button>
                </div>
            </div>
        </div>
    @endif
</div>
