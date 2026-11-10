<div class="flex h-full flex-col overflow-hidden">
    <x-page-header title="Einstellungen" subtitle="Kein Pfad ist fest im Code – Startwerte kommen aus der .env.">
        @if (in_array($tab, ['general', 'categories', 'access']))
            <button wire:click="save" class="btn-primary">Speichern</button>
        @endif
    </x-page-header>

    <div class="flex shrink-0 gap-1 border-b border-zinc-800 px-6 py-2">
        @foreach (['general' => 'Allgemein', 'categories' => 'Kategorien & Muster', 'genres' => 'Genre-Liste', 'mapping' => 'Genre-Zuordnung', 'auto' => 'Automatiken', 'access' => 'Zugangsdaten', 'backup' => 'Sichern & Zurücksetzen'] as $k => $label)
            <button wire:click="$set('tab', '{{ $k }}')" class="{{ $tab === $k ? 'tab-active' : 'tab' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-200">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-200">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif

        @if ($tab === 'general')
            <div class="grid max-w-4xl grid-cols-2 gap-5">
                <div class="col-span-2"><label class="label">Basis (Wurzel des Musik-Shares im Container)</label><input wire:model="s.base" class="input font-mono"></div>
                <div class="col-span-2"><label class="label">Inbox-Ordner</label><input wire:model="s.inbox" class="input font-mono"></div>
                <div><label class="label">Papierkorb-Ordner</label><input wire:model="s.trash" class="input font-mono"></div>
                <div><label class="label">Ordner für Künstlerbilder</label><input wire:model="s.artist_images" class="input font-mono"></div>
                <div><label class="label">Text für fehlendes Album</label><input wire:model="s.no_album" class="input"></div>
                <div><label class="label">Ordner für Nicht-Buchstaben</label><input wire:model="s.non_letter_folder" class="input"></div>
                <div><label class="label">Album-Interpret für Sampler</label><input wire:model="s.compilation_artist" class="input"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label">Ersatz für „:“</label><input wire:model="s.replace_colon" class="input"></div>
                    <div><label class="label">Ersatz für „/“</label><input wire:model="s.replace_slash" class="input"></div>
                </div>
                <div><label class="label">ID3-Version beim Schreiben (MP3)</label>
                    <select wire:model="s.id3_version" class="input"><option value="4">ID3v2.4 (Mehrfachwerte nativ)</option><option value="3">ID3v2.3 (Mehrfachwerte mit „; “)</option></select>
                </div>
                <div><label class="label">Scan-Drosselung (ms pro Datei)</label><input type="number" wire:model="s.scan_throttle_ms" class="input"></div>
                <div><label class="label">„Sehr kleines Cover“ unter (px)</label><input type="number" wire:model="s.small_cover_px" class="input"></div>
                <div><label class="label">Umgedrehte Namen (Aufräumen)</label>
                    <select wire:model="s.invert_names" class="input">
                        <option value="off">Aus – Sortierschreibweise „Crystals, The“ bleibt</option>
                        <option value="articles">Nur Artikel – „Crystals, The“ → „The Crystals“</option>
                        <option value="persons">Artikel und Personen – auch „Berry, Chuck“ → „Chuck Berry“</option>
                    </select>
                </div>
                <div><label class="label">Qobuz-Shop (FLAC-Kandidaten)</label>
                    <select wire:model="s.qobuz_store" class="input">
                        @foreach (['de-de' => 'Deutschland', 'at-de' => 'Österreich', 'ch-de' => 'Schweiz (Deutsch)', 'fr-fr' => 'Frankreich', 'gb-en' => 'Großbritannien', 'us-en' => 'USA'] as $v => $label)<option value="{{ $v }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-span-full">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="s.inbox_watch" class="size-4 rounded border-zinc-600 bg-zinc-800 text-violet-500"> Inbox beobachten</label>
                    <p class="mt-1 text-xs text-zinc-500">Prüft jede Minute, ob neue Dateien in der Inbox liegen, und liest sie dann ein. Es wird nur gelesen – eingeordnet wird weiter von Hand.</p>
                </div>
            </div>
        @endif

        @if ($tab === 'categories')
            <p class="mb-4 max-w-4xl text-sm text-zinc-400">
                Platzhalter: <code class="text-violet-300">{buchstabe} {album_buchstabe} {ordner_interpret} {album} {nr} {interpret} {jahr} {titel} {ext}</code>.
                <code class="text-violet-300">{buchstabe}</code> ist der Anfangsbuchstabe des Ordner-Interpreten, <code class="text-violet-300">{album_buchstabe}</code> der des Albums (für Sampler).
                Leere Platzhalter fallen samt Klammern bzw. Trenner weg. Der Name ist zugleich der Wert im Kommentar-Tag.
                Die Kategorien sind fest: Lib und Blackhole mit je einem Ordner für Künstler und einem für Sampler.
            </p>
            <div class="space-y-4">
                @foreach ($s['categories'] as $i => $c)
                    @if ($i === 0 || $s['categories'][$i - 1]['area'] !== $c['area'])
                        <h3 class="pt-2 text-sm font-semibold uppercase tracking-wide text-zinc-400">{{ $c['area'] === 'blackhole' ? 'Blackhole' : 'Lib' }}</h3>
                    @endif
                    <div class="card p-4" wire:key="cat{{ $c['role'] }}">
                        <div class="grid grid-cols-[14rem_14rem_1fr_auto] items-end gap-3">
                            <div><label class="label">Name (Kommentar)</label><input wire:model.live.debounce.500ms="s.categories.{{ $i }}.name" class="input font-semibold"></div>
                            <div><label class="label">Zielordner (unter Basis)</label><input wire:model.live.debounce.500ms="s.categories.{{ $i }}.folder" class="input font-mono"></div>
                            <div><label class="label">Dateinamen-Muster</label><input wire:model.live.debounce.500ms="s.categories.{{ $i }}.pattern" class="input font-mono"></div>
                            <div class="pb-2 text-sm text-zinc-400">{{ $c['compilation'] ? 'Sampler' : 'Künstler' }}</div>
                        </div>
                        <div class="mt-3 space-y-0.5 rounded-lg bg-zinc-950/60 p-3 font-mono text-xs text-blue-300">
                            @foreach ($categoriesPreview[$i] ?? [] as $p)<div class="truncate" title="{{ $p }}">{{ $p }}</div>@endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($tab === 'genres')
            <div class="max-w-3xl">
                <p class="mb-4 text-sm text-zinc-400">In <code>GENRE</code> steht nur, was auf dieser Liste steht. Unterstile schreiben das Hauptgenre mit (Electronic + Trance).</p>
                <div class="mb-5 flex gap-2">
                    <input wire:model="newGenre" wire:keydown.enter="addGenre" placeholder="Neues Genre" class="input">
                    <select wire:model="newGenreParent" class="input w-64">
                        <option value="">als Hauptgenre</option>
                        @foreach ($genreTree as $g)<option value="{{ $g->id }}">Unterstil von {{ $g->name }}</option>@endforeach
                    </select>
                    <button wire:click="addGenre" class="btn-primary">Hinzufügen</button>
                </div>
                <div class="space-y-1">
                    @foreach ($genreTree as $g)
                        @foreach (array_merge([$g], $g->children->all()) as $item)
                            <div wire:key="g{{ $item->id }}" class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-zinc-900 {{ $item->parent_id ? 'ml-8' : '' }}">
                                <input value="{{ $item->name }}" wire:change="renameGenre({{ $item->id }}, $event.target.value)" class="input-sm {{ $item->parent_id ? '' : 'font-semibold' }}">
                                <button wire:click="moveGenre({{ $item->id }}, -1)" class="btn-ghost btn-sm">↑</button>
                                <button wire:click="moveGenre({{ $item->id }}, 1)" class="btn-ghost btn-sm">↓</button>
                                <button wire:click="deleteGenre({{ $item->id }})" data-confirm-label="Löschen" wire:confirm="Genre „{{ $item->name }}“ löschen?" class="btn-ghost btn-sm hover:text-red-400">✕</button>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endif

        @if ($tab === 'mapping')
            <div class="max-w-4xl">
                <p class="mb-4 text-sm text-zinc-400">Jeder Genre-Wert aus Lib und Inbox, der nicht auf der Liste steht, wird einmal einem Listen-Genre zugeordnet. Daraus entstehen Vorschläge in der Inbox; angewendet wird über „Aufräumen“.</p>
                <input type="search" wire:model.live.debounce.300ms="mappingFilter" placeholder="Filtern …" class="input mb-4 w-72">
                <table class="w-full text-sm">
                    <thead><tr><th class="th">Wert im Bestand</th><th class="th w-24 text-right">Dateien</th><th class="th w-80">Listen-Genre</th></tr></thead>
                    <tbody>
                    @forelse ($mappings as $source => $n)
                        <tr wire:key="m{{ md5($source) }}">
                            <td class="td">{{ $source }}</td>
                            <td class="td text-right text-zinc-400">{{ $n }}</td>
                            <td class="td">
                                <select wire:change="setMapping(@js($source), $event.target.value)" class="input-sm {{ empty($mapped[$source]) ? 'border-amber-500/50' : '' }}">
                                    <option value="">— nicht zugeordnet —</option>
                                    @foreach ($genreOptions as $o)<option value="{{ $o['name'] }}" @selected(($mapped[$source] ?? null) === $o['name'])>{{ $o['label'] }}</option>@endforeach
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="td py-8 text-center text-zinc-500">Keine fremden Genre-Werte (erst Bestand scannen).</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        @if ($tab === 'auto')
            <div class="max-w-6xl">
                <p class="mb-4 text-sm text-zinc-400">
                    Angehakte Aufräumaktionen werden <strong class="text-zinc-200">ohne Nachfrage</strong> erledigt – nach jedem Lib-Scan oder per Knopf.
                    Sie stehen im Protokoll und im Verlauf und lassen sich dort rückgängig machen. Die Kategorie ergibt sich aus dem Ordner der Datei.
                    Aufräumen gilt nur im Bestand – die Inbox wird beim Einordnen bereinigt. Standardmäßig ist alles aus.
                </p>
                <table class="w-full text-sm">
                    <thead>
                    <tr>
                        <th class="th">Aufräumaktion</th>
                        @foreach ($autoColumns as $col)
                            <th class="th w-36 text-center">{{ $col }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($autoChecks as $check => [$label, $description, $fix])
                        <tr wire:key="auto-{{ $check }}" class="hover:bg-zinc-900/60">
                            <td class="td py-2">
                                <div class="font-medium">{{ $label }}</div>
                                <div class="text-xs text-zinc-500">{{ $fix }}</div>
                                @isset($risky[$check])<div class="text-xs text-amber-300">Achtung: {{ $risky[$check] }}</div>@endisset
                            </td>
                            @foreach ($autoColumns as $col)
                                @php $on = ! empty($autoConfig[$check][$col]); $n = $autoPreview[$check][$col] ?? 0; @endphp
                                <td class="td text-center">
                                    <label class="inline-flex cursor-pointer flex-col items-center gap-0.5">
                                        <input type="checkbox" wire:click="toggleAuto(@js($check), @js($col))" @checked($on) class="size-4 rounded border-zinc-600 bg-zinc-800 text-violet-500">
                                        <span class="text-[11px] {{ $n ? ($on ? 'text-violet-300' : 'text-zinc-400') : 'text-zinc-600' }}" title="Einzelne Schritte (meist Dateien), die aktuell anstehen">{{ $n }} {{ $n === 1 ? "Schritt" : "Schritte" }}</span>
                                    </label>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @foreach ($autoHeld as $h)
                    <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
                        „{{ $h['label'] }}“ wurde angehalten: {{ $h['count'] }} Schritte auf einmal (mehr als {{ \App\Support\AutoCleanup::LIMIT }} laufen nie ohne Nachfrage). Bitte über <a href="{{ route('cleanup') }}" wire:navigate class="underline">Aufräumen</a> mit Vorschau prüfen.
                    </div>
                @endforeach
                <div class="mt-4 flex items-center gap-3">
                    <button wire:click="runAuto" data-confirm-label="Jetzt ausführen" wire:confirm="Alle angehakten Automatiken jetzt ausführen?" class="btn-primary" @disabled(! $autoConfig)>Jetzt ausführen</button>
                    <span class="text-xs text-zinc-500">Läuft sonst automatisch nach jedem Lib-Scan.</span>
                </div>
            </div>
        @endif

        @if ($tab === 'backup')
            @php $sections = \App\Support\SettingsBackup::SECTIONS; @endphp
            <div class="grid max-w-5xl gap-6">
                <p class="text-sm text-zinc-400">Musikdateien, Verlauf und Papierkorb werden hier nie verändert.</p>

                <div class="card p-5">
                    <h3 class="font-semibold">Exportieren</h3>
                    <p class="mt-1 text-sm text-zinc-400">Alle selbst gepflegten Daten als JSON-Datei: {{ implode(', ', array_map(fn ($s) => $s[0], $sections)) }}.</p>
                    <div class="mt-3 flex items-center gap-4">
                        <a href="{{ route('settings.export', ['secrets' => $exportSecrets ? 1 : 0, 'images' => $exportImages ? 1 : 0]) }}" class="btn-primary">Einstellungen exportieren</a>
                        <label class="flex items-center gap-2 text-sm text-zinc-300">
                            <input type="checkbox" wire:model.live="exportImages" class="rounded border-zinc-600 bg-zinc-800">
                            Künstlerbilder mitsichern (ZIP)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-zinc-300">
                            <input type="checkbox" wire:model.live="exportSecrets" class="rounded border-zinc-600 bg-zinc-800">
                            Zugangsdaten (Discogs-Token, Fanart.tv-Key) mit exportieren
                        </label>
                    </div>
                </div>

                <div class="card p-5">
                    <h3 class="font-semibold">Importieren</h3>
                    <p class="mt-1 text-sm text-zinc-400">Eine exportierte Datei einspielen. Gewählte Bereiche werden ersetzt.</p>
                    <label class="btn-ghost mt-3 cursor-pointer">Sicherung wählen …<input type="file" accept=".zip,.json,application/zip,application/json" wire:model="importFile" class="hidden"></label>
                    <span wire:loading wire:target="importFile" class="ml-2 text-sm text-zinc-400">liest …</span>
                    @error('importFile')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
                    @if ($importSummary)
                        <div class="mt-3 space-y-1.5">
                            @foreach (array_intersect_key($sections, array_flip(\App\Support\SettingsBackup::BACKUP_SECTIONS)) as $key => [$label, $hint])
                                <label class="flex items-center gap-2 text-sm {{ ($importSummary[$key] ?? 0) ? 'text-zinc-200' : 'text-zinc-600' }}">
                                    <input type="checkbox" wire:model.live="importSections" value="{{ $key }}" @disabled(! ($importSummary[$key] ?? 0)) class="rounded border-zinc-600 bg-zinc-800">
                                    {{ $label }} <span class="text-xs text-zinc-500">({{ $importSummary[$key] ?? 0 }} Einträge{{ $key === 'artists' && $importImages ? ', '.$importImages.' Bilder' : '' }})</span>
                                </label>
                            @endforeach
                        </div>
                        @if (($importSummary['auto_cleanup'] ?? 0) && in_array('settings', $importSections, true))
                            <p class="mt-2 text-xs text-amber-300">Die Einstellungen enthalten {{ $importSummary['auto_cleanup'] }} freigegebene Automatik(en) – sie laufen nach dem nächsten Lib-Scan ohne Nachfrage (höchstens {{ \App\Support\AutoCleanup::LIMIT }} Schritte je Automatik, sonst wird angehalten). Prüfen unter „Automatiken“.</p>
                        @endif
                        <button wire:click="runImport" data-confirm-label="Ersetzen" wire:confirm="Gewählte Bereiche durch die Sicherung ersetzen?" class="btn-primary mt-3" @disabled(! $importSections)>Einspielen</button>
                    @endif
                </div>

                <div class="card border-red-500/30 p-5">
                    <h3 class="font-semibold text-red-200">Zurücksetzen</h3>
                    <p class="mt-1 text-sm text-zinc-400">Tipp: vorher exportieren.</p>
                    <div class="mt-3 space-y-2">
                        <label class="flex items-start gap-2 text-sm text-zinc-200">
                            <input type="radio" wire:model.live="resetMode" value="all" class="mt-0.5 border-zinc-600 bg-zinc-800">
                            <span><strong>Alles – Werkszustand</strong>, als wäre MusicWiz nie benutzt worden: komplette Datenbank (Einstellungen, Künstler, Genres,
                                Scan-Daten, Aufräumen, Verlauf, Warteschlange), Cover-Vorschauen und Rückgängig-Sicherungen.
                                <span class="block text-xs text-zinc-500">Bleibt unverändert: deine Musik, der Papierkorb-Ordner und die Künstlerbilder auf dem Share. „Rückgängig“ für frühere Aktionen ist danach nicht mehr möglich.</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm text-zinc-200">
                            <input type="radio" wire:model.live="resetMode" value="sections" class="mt-0.5 border-zinc-600 bg-zinc-800">
                            <span>Nur ausgewählte Bereiche</span>
                        </label>
                    </div>
                    @if ($resetMode === 'sections')
                        <div class="mt-2 ml-6 space-y-1.5">
                            @foreach ($sections as $key => [$label, $hint])
                                <label class="flex items-center gap-2 text-sm text-zinc-200">
                                    <input type="checkbox" wire:model.live="resetSections" value="{{ $key }}" class="rounded border-zinc-600 bg-zinc-800">
                                    {{ $label }} <span class="text-xs text-zinc-500">– {{ $hint }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-3 flex items-center gap-3">
                        <input wire:model="resetConfirm" placeholder="ZURÜCKSETZEN eintippen" class="input w-64">
                        <button wire:click="runReset" class="btn-danger" @disabled($resetMode === 'sections' && ! $resetSections)>{{ $resetMode === 'all' ? 'Alles zurücksetzen' : 'Bereiche zurücksetzen' }}</button>
                    </div>
                    @error('resetConfirm')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
            </div>
        @endif

        @if ($tab === 'access')
            <div class="grid max-w-3xl gap-5">
                <div><label class="label">Discogs-Token</label><div class="flex gap-2" x-data="{ show: false }"><input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="s.discogs_token" class="input font-mono" autocomplete="off" spellcheck="false"><button type="button" x-on:click="show = ! show" class="btn-ghost shrink-0" x-text="show ? 'Verbergen' : 'Anzeigen'">Anzeigen</button></div>
                    <p class="mt-1 text-xs text-zinc-500">discogs.com › Settings › Developers › „Generate new token“ (60 Abfragen/min).</p></div>
                <div><label class="label">Fanart.tv-API-Key</label><div class="flex gap-2" x-data="{ show: false }"><input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="s.fanart_key" class="input font-mono" autocomplete="off" spellcheck="false"><button type="button" x-on:click="show = ! show" class="btn-ghost shrink-0" x-text="show ? 'Verbergen' : 'Anzeigen'">Anzeigen</button></div>
                    <p class="mt-1 text-xs text-zinc-500">Kostenloser Key auf fanart.tv; Suche läuft über die MusicBrainz-ID des Künstlers.</p></div>
                <div><label class="label">AcoustID-Anwendungsschlüssel</label><div class="flex gap-2" x-data="{ show: false }"><input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="s.acoustid_key" class="input font-mono" autocomplete="off" spellcheck="false"><button type="button" x-on:click="show = ! show" class="btn-ghost shrink-0" x-text="show ? 'Verbergen' : 'Anzeigen'">Anzeigen</button></div>
                    <p class="mt-1 text-xs text-zinc-500">Kostenlos auf acoustid.org › „Register your application“. Damit erkennt der Album-Editor Titel ohne Tags am Fingerabdruck.</p></div>
                <div><label class="label">Spotify (Client-ID und Client-Secret)</label>
                    <div class="grid gap-2 sm:grid-cols-2" x-data="{ show: false }">
                        <input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="s.spotify_client_id" placeholder="Client-ID" class="input font-mono" autocomplete="off" spellcheck="false">
                        <div class="flex gap-2"><input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="s.spotify_client_secret" placeholder="Client-Secret" class="input font-mono" autocomplete="off" spellcheck="false"><button type="button" x-on:click="show = ! show" class="btn-ghost shrink-0" x-text="show ? 'Verbergen' : 'Anzeigen'">Anzeigen</button></div>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500">Kostenlos auf developer.spotify.com › Dashboard › „Create app“ (Redirect-URI beliebig, z. B. http://localhost). Liefert Künstlerfotos, Genres und – zusammen mit Deezer und MusicBrainz – ob ein Kombi-Name ein Act oder eine Zusammenarbeit ist.</p></div>
                <div><label class="label">TheAudioDB-API-Key</label><div class="flex gap-2" x-data="{ show: false }"><input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="s.audiodb_key" placeholder="leer = freier Test-Key (30 Abfragen/min)" class="input font-mono" autocomplete="off" spellcheck="false"><button type="button" x-on:click="show = ! show" class="btn-ghost shrink-0" x-text="show ? 'Verbergen' : 'Anzeigen'">Anzeigen</button></div>
                    <p class="mt-1 text-xs text-zinc-500">Premium-Key aus dem Profil auf theaudiodb.com (100 Abfragen/min). Liefert Künstlerfotos, Fanart, Logo, Genre/Stil und Biografie – gesucht über die MusicBrainz-ID, also immer der richtige Künstler.</p></div>
                <p class="text-xs text-zinc-500">MusicBrainz, Deezer und LRCLIB (Songtexte) brauchen keine Anmeldung. Der Container braucht für das Nachschlagen Internetzugang.</p>
            </div>
        @endif
    </div>
</div>
