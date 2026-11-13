<div class="flex min-h-full">
    {{-- zweite Leiste: Bereiche der Schreibweisen --}}
    @if ($tab === 'schreibweisen')
        <aside class="sticky top-0 h-[calc(100vh-4rem)] w-60 shrink-0 border-r border-zinc-800 bg-zinc-950 px-3 py-4">
            <div class="mb-2 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Schreibweisen</div>
            @foreach (\App\Livewire\SettingsPage::SECTIONS as $key => $label)
                @php $count = $key === 'sortierung' ? count(array_filter(explode(',', $articles), 'trim')) : count($rules[$key === 'names' ? 'word' : 'various'] ?? []); @endphp
                <button wire:click="$set('section', '{{ $key }}')"
                        @class(['flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-sm',
                                'bg-accent/10 text-accent font-medium' => $section === $key,
                                'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => $section !== $key])>
                    @if ($key === 'sortierung')
                        <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M4 6h9M4 12h6M4 18h3M17 4v16m0 0-3-3m3 3 3-3"/></svg>
                    @elseif ($key === 'various')
                        <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M15 14.5a5 5 0 0 1 6.5 4.5"/></svg>
                    @else
                        <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></svg>
                    @endif
                    <span class="flex-1 leading-tight">{{ $label }}</span>
                    <span class="rounded-full px-1.5 text-xs tabular-nums text-zinc-500">{{ $count }}</span>
                </button>
            @endforeach
        </aside>
    @endif
    <div class="min-w-0 flex-1">
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-4">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Einstellungen</h1>
                <p class="text-xs text-zinc-500">{{ ['zugangsdaten' => 'Zugangsdaten werden verschlüsselt in der Datenbank gespeichert.', 'ordner' => 'Ordner aus der .env und Vorlagen, nach denen Dateien benannt werden.', 'schreibweisen' => 'Regeln für Interpret und Album-Interpret – angewendet nur in der Datenbank, als System-Änderung.', 'musicbrainz' => 'Die Interpreten von MusicBrainz lokal – für Abgleich, Kombis und Schreibweisen ohne Abfragen beim Dienst.', 'sonstiges' => 'Grenzwerte für Prüfungen.'][$tab] ?? '' }}</p>
            </div>
            <div class="flex rounded-md border border-zinc-700 p-0.5 text-sm">
                @foreach (\App\Livewire\SettingsPage::TABS as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                            @class(['rounded px-3 py-1 font-medium', 'bg-zinc-700 text-zinc-100' => $tab === $key, 'text-zinc-400 hover:text-zinc-200' => $tab !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </header>

    @if ($tab === 'zugangsdaten')
    <div class="max-w-3xl px-6 py-6">
        <section>
            <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Zugangsdaten</h2>
            <p class="mb-4 text-sm text-zinc-500">Für Nachschlagen, Bilder und den Assistenten – übernommen aus der bisherigen Version.</p>
            <form wire:submit="save" class="space-y-4">
                @foreach (\App\Support\Settings::SECRETS as $key => [$label, $hint, $link])
                    <div x-data="{ show: false }">
                        <div class="mb-1 flex items-baseline justify-between gap-3">
                            <label for="secret-{{ $key }}" class="text-sm font-medium text-zinc-300">{{ $label }}</label>
                            <a href="{{ $link }}" target="_blank" rel="noopener" class="text-xs text-accent hover:underline">Key holen ↗</a>
                        </div>
                        <div class="flex gap-2">
                            <input id="secret-{{ $key }}" :type="show ? 'text' : 'password'" type="password" wire:model="secrets.{{ $key }}"
                                   class="field font-mono" autocomplete="off" spellcheck="false">
                            <button type="button" class="btn shrink-0 w-28 justify-center" @click="show = !show" x-text="show ? 'Verbergen' : 'Anzeigen'">Anzeigen</button>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500">{{ $hint }}</p>
                    </div>
                @endforeach
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="btn-primary">Speichern</button>
                    @if ($saved)<span class="text-sm text-emerald-400">{{ $saved }}</span>@endif
                </div>
            </form>
        </section>

    </div>
    @elseif ($tab === 'ordner')
    <div class="max-w-4xl space-y-10 px-6 py-6">
        <section>
            <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Ordner</h2>
            <p class="mb-4 text-sm text-zinc-500">Festgelegt in der <code class="text-zinc-400">.env</code> (MUSIC_INBOX, MUSIC_LIBRARY, MUSIC_BLACKHOLE, MUSIC_TRASH, MUSIC_ARTIST_IMAGES).</p>
            <dl class="divide-y divide-zinc-800 rounded-lg border border-zinc-800">
                @foreach ($folders as $label => $f)
                    <div class="flex items-center gap-4 px-4 py-2.5 text-sm">
                        <dt class="w-36 shrink-0 text-zinc-400">{{ $label }}</dt>
                        <dd class="flex-1 truncate font-mono text-zinc-200">{{ $f['path'] }}</dd>
                        <dd @class(['text-xs', 'text-emerald-400' => $f['ok'], 'text-red-400' => ! $f['ok']])>{{ $f['ok'] ? 'erreichbar' : 'fehlt' }}</dd>
                    </div>
                @endforeach
                <div class="flex items-center gap-4 px-4 py-2.5 text-sm">
                    <dt class="w-36 shrink-0 text-zinc-400">Parallele Leser</dt>
                    <dd class="flex-1 text-zinc-200">{{ $workers }} <span class="text-xs text-zinc-500">(MUSIC_SCAN_WORKERS)</span></dd>
                </div>
            </dl>
        </section>

        <section x-data="{ field: null, insert(p) { if (!this.field) return; const f = this.field, i = f.selectionStart ?? f.value.length; f.value = f.value.slice(0, i) + p + f.value.slice(f.selectionEnd ?? i); f.dispatchEvent(new Event('input')); f.focus(); f.setSelectionRange(i + p.length, i + p.length) } }">
            <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Dateinamen</h2>
            <p class="mb-4 text-sm text-zinc-500">
                Vorlagen relativ zum Ordner von Lib bzw. Blackhole – Sampler sind Alben mit Sampler-Flag oder Album-Interpret „{{ \App\Support\NameRules::VARIOUS }}“.
                Leere Platzhalter fallen samt Klammern bzw. „ - “ weg. Noch wird nichts umbenannt – die Beispiele zeigen, wie Lieder aus dem Bestand heißen würden.
            </p>
            <div class="mb-5 flex flex-wrap gap-1.5">
                @foreach (\App\Support\PathTemplate::PLACEHOLDERS as $ph => $desc)
                    <button type="button" class="rounded bg-zinc-800 px-2 py-0.5 font-mono text-xs text-zinc-300 hover:bg-zinc-700 hover:text-zinc-100" title="{{ $desc }} – klicken fügt ein" @mousedown.prevent @click="insert('{{ '{'.$ph.'}' }}')">{{ '{'.$ph.'}' }}</button>
                @endforeach
            </div>
            <form wire:submit="savePaths" class="space-y-5">
                @foreach (\App\Support\PathTemplate::TEMPLATES as $key => [$location, $compilation, $label])
                    <div wire:key="tpl-{{ $key }}">
                        <div class="mb-1 flex items-baseline justify-between gap-3">
                            <label for="tpl-{{ $key }}" class="text-sm font-medium text-zinc-300">{{ $label }}</label>
                            @if (($paths[$key] ?? '') !== \App\Support\PathTemplate::TEMPLATES[$key][3])
                                <button type="button" class="text-xs text-zinc-500 hover:text-zinc-200" wire:click="resetTemplate('{{ $key }}')">Vorgabe</button>
                            @endif
                        </div>
                        <input id="tpl-{{ $key }}" type="text" wire:model.live.debounce.300ms="paths.{{ $key }}" @focus="field = $el" class="field font-mono text-sm" spellcheck="false">
                        @if ($e = $pathErrors[$key] ?? \App\Support\PathTemplate::problem($paths[$key] ?? ''))
                            <p class="mt-1 text-xs text-red-400">{{ $e }}</p>
                        @else
                            <div class="mt-1.5 space-y-0.5 text-xs">
                                @forelse ($examples[$key] ?? [] as $ex)
                                    <div class="truncate font-mono" title="bisher: {{ $ex['from'] }}"><span class="text-zinc-600">{{ \App\Support\Locations::LABELS[$location] }}/</span><span class="text-zinc-300">{{ $ex['to'] }}</span></div>
                                @empty
                                    <div class="text-zinc-600">Keine passenden Lieder im Bestand für ein Beispiel.</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="grid grid-cols-2 gap-4 pt-2">
                    @foreach (\App\Support\PathTemplate::OPTIONS as $key => [$label, $default, $hint])
                        <div wire:key="opt-{{ $key }}">
                            <label for="opt-{{ $key }}" class="mb-1 block text-sm font-medium text-zinc-300">{{ $label }}</label>
                            <input id="opt-{{ $key }}" type="text" wire:model.live.debounce.300ms="paths.{{ $key }}" class="field" placeholder="{{ $default }}">
                            <p @class(['mt-1 text-xs', 'text-red-400' => isset($pathErrors[$key]), 'text-zinc-500' => ! isset($pathErrors[$key])])>{{ $pathErrors[$key] ?? $hint }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="btn-primary">Speichern</button>
                    @if ($pathsSaved)<span class="text-sm text-emerald-400">{{ $pathsSaved }}</span>@endif
                </div>
            </form>
        </section>
    </div>
    @elseif ($tab === 'schreibweisen' && $section === 'sortierung')
    <div class="max-w-3xl px-6 py-6">
        @if ($message)
            <div class="mb-4 rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
        @endif
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Sortiernamen</h2>
        <p class="mb-4 text-sm text-zinc-500">Steht einer dieser Artikel vorn, kommt er im Sortiernamen nach hinten („The Beatles“ → „Beatles, The“). Personen werden nie umgedreht. Umgedrehte Anzeigenamen („Art of Noise, The“) werden zu „The Art of Noise“. Eigene Sortiernamen je Interpret unter <a href="{{ route('artists', ['ansicht' => 'sortiernamen']) }}" wire:navigate class="underline">Interpreten › Sortiernamen</a>.</p>
        <form wire:submit="saveArticles" class="space-y-3">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-zinc-300">Artikel, mit Komma getrennt</span>
                <input type="text" wire:model="articles" class="field" placeholder="{{ \App\Support\SortNames::DEFAULT_ARTICLES }}">
            </label>
            <button type="submit" class="btn-primary">Speichern und anwenden</button>
        </form>
    </div>
    @elseif ($tab === 'schreibweisen')
        @php
            $kind = $section === 'names' ? 'word' : 'various';
            $list = $rules[$kind] ?? collect();
        @endphp
        <div class="max-w-3xl px-6 py-6">
            @if ($section === 'various')
                <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Various Artists</h2>
                <p class="mb-4 text-sm text-zinc-500">
                    Steht eine dieser Schreibweisen als ganzer Wert im Interpreten oder Album-Interpreten, wird daraus „{{ \App\Support\NameRules::VARIOUS }}“.
                    Groß/klein, Akzente, Punkte, Striche, Leerzeichen und ein Klammerzusatz am Ende zählen nicht – „V.A.“ deckt also auch „VA“, „V. A.“ und „v/a“ ab.
                </p>
            @else
                <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-400">Namen</h2>
                <p class="mb-4 text-sm text-zinc-500">
                    Suchtext im Interpreten oder Album-Interpreten ersetzen, ohne Groß/klein – z. B. „d.r. Alban“ → „Dr. Alban“, „Dj Bobo“ → „DJ Bobo“.
                    <span class="text-zinc-400">Eigenes Wort</span>: nur wenn davor und danach kein Buchstabe und keine Ziffer steht („Drake“ bleibt);
                    <span class="text-zinc-400">auch im Wort</span>: überall. Steht die Ersetzung an der Stelle schon, bleibt es.
                </p>
            @endif

            @if ($section === 'various')
                <form wire:submit="addRule" class="mb-3 flex gap-2">
                    <input type="text" wire:model="newRule" class="field max-w-sm" placeholder="z. B. Verschiedene Interpreten">
                    <button type="submit" class="btn-primary shrink-0">Hinzufügen</button>
                </form>
            @else
                <form wire:submit="addRule" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                    <input type="text" wire:model="newReplacement" class="field w-36" placeholder="z. B. Dr.">
                    <span class="text-zinc-500">wird geschrieben, wenn gefunden:</span>
                    <input type="text" wire:model="newRule" class="field w-36" placeholder="z. B. d.r.">
                    <select wire:model="newWholeWord" class="field w-auto">
                        <option value="1">als eigenes Wort</option>
                        <option value="0">auch im Wort</option>
                    </select>
                    <button type="submit" class="btn-primary shrink-0">Hinzufügen</button>
                </form>
            @endif
            @if ($message)<p class="mb-3 text-sm text-emerald-400">{{ $message }}</p>@endif
            @if ($error)<p class="mb-3 text-sm text-red-400">{{ $error }}</p>@endif

            @if ($section === 'various')
                <div class="divide-y divide-zinc-800 rounded-lg border border-zinc-800">
                    @forelse ($list as $rule)
                        <div class="flex items-center gap-4 px-4 py-2 text-sm" wire:key="rule-{{ $rule->id }}">
                            <span class="flex-1 text-zinc-200">{{ $rule->value }}</span>
                            <span class="text-xs text-zinc-500">→ {{ \App\Support\NameRules::VARIOUS }}</span>
                            <button class="text-xs text-zinc-500 hover:text-red-400" wire:click="removeRule({{ $rule->id }})" title="Regel entfernen – angepasste Lieder bleiben so">entfernen</button>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm text-zinc-500">Noch keine Regeln.</div>
                    @endforelse
                </div>
            @else
                {{-- gruppiert nach Schreibweise: „Dr.“ wird geschrieben, wenn „Dr“, „D.R.“ … gefunden wird --}}
                <div class="space-y-2">
                    @forelse ($list->groupBy('replacement')->sortKeys(SORT_NATURAL | SORT_FLAG_CASE) as $replacement => $rules)
                        <div class="rounded-lg border border-zinc-800 px-4 py-3" wire:key="group-{{ md5($replacement) }}">
                            <div class="mb-2 text-sm text-zinc-500"><span class="font-mono text-base font-semibold text-zinc-100">{{ $replacement }}</span> wird geschrieben, wenn gefunden:</div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($rules->sortBy('value', SORT_NATURAL | SORT_FLAG_CASE) as $rule)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-800 py-0.5 pl-3 pr-1 text-sm" wire:key="rule-{{ $rule->id }}">
                                        <span class="font-mono text-zinc-200">{{ $rule->value }}</span>
                                        <button wire:click="toggleWholeWord({{ $rule->id }})" title="Umschalten: als eigenes Wort ↔ auch im Wort"
                                                @class(['rounded-full px-1.5 text-[11px]', 'text-zinc-500 hover:bg-zinc-700 hover:text-zinc-200' => $rule->whole_word, 'bg-amber-500/15 text-amber-300 hover:bg-amber-500/25' => ! $rule->whole_word])>{{ $rule->whole_word ? 'eigenes Wort' : 'auch im Wort' }}</button>
                                        <button class="grid size-5 place-items-center rounded-full text-zinc-500 hover:bg-zinc-700 hover:text-red-400" wire:click="removeRule({{ $rule->id }})" title="Entfernen – angepasste Lieder bleiben so">×</button>
                                    </span>
                                @endforeach
                                <form class="inline-flex" x-data="{ open: false, v: '' }" @submit.prevent="if (v.trim()) { $wire.addTo(@js($replacement), v); v = ''; open = false }">
                                    <button type="button" x-show="!open" @click="open = true; $nextTick(() => $refs.i.focus())" class="rounded-full px-2.5 py-0.5 text-sm text-accent hover:bg-accent/10">+ Schreibweise</button>
                                    <input x-show="open" x-cloak x-ref="i" x-model="v" @keydown.escape="open = false" @blur="if (!v) open = false" class="field w-36 py-0.5 text-sm" placeholder="gefunden z. B. dr">
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-zinc-800 px-4 py-6 text-center text-sm text-zinc-500">Noch keine Regeln.</div>
                    @endforelse
                </div>
            @endif
            <p class="mt-3 text-xs text-zinc-500">Neue Regeln gelten sofort für alle Lieder und bei jedem Einlesen. Interpreten mit dem alten Namen gehen im neuen auf (der alte Name bleibt als Schreibweise).</p>
        </div>
    @elseif ($tab === 'musicbrainz')
    @php $fmt = fn ($n) => number_format($n, 0, ',', '.'); @endphp
    <div class="max-w-3xl space-y-6 px-6 py-6">
        <section class="rounded-lg border border-zinc-800 bg-zinc-900 p-5">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">Lokale MusicBrainz-Daten</h2>
            @if ($musicbrainz['dump'])
                <dl class="grid grid-cols-[10rem_1fr] gap-y-1.5 text-sm">
                    <dt class="text-zinc-500">Stand</dt><dd>{{ $musicbrainz['dump'] }}@if ($musicbrainz['timestamp']) <span class="text-zinc-500">({{ \Illuminate\Support\Str::before($musicbrainz['timestamp'], '.') }})</span>@endif</dd>
                    <dt class="text-zinc-500">Geladen am</dt><dd>{{ \Illuminate\Support\Carbon::parse($musicbrainz['imported_at'])->format('d.m.Y H:i') }}</dd>
                    <dt class="text-zinc-500">Interpreten</dt><dd class="tabular-nums">{{ $fmt($musicbrainz['artists']) }}</dd>
                    <dt class="text-zinc-500">Namen und Schreibweisen</dt><dd class="tabular-nums">{{ $fmt($musicbrainz['names']) }}</dd>
                </dl>
            @else
                <p class="text-sm text-zinc-400">Noch nicht geladen – bis dahin fragt MusicBrainz jedes Mal über die öffentliche Schnittstelle (langsam, wird oft gebremst).</p>
            @endif
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button class="btn-primary" wire:click="updateMusicBrainz" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('musicbrainz')">
                    {{ $musicbrainz['dump'] ? 'Jetzt aktualisieren' : 'Jetzt laden' }}
                </button>
                @if ($musicbrainz['dump'])
                    <button class="btn" wire:click="updateMusicBrainz(true)" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('musicbrainz')"
                            title="Auch laden, wenn es keinen neueren Stand gibt">Neu laden</button>
                @endif
                <span class="text-xs text-zinc-500" x-show="$store.activity.isRunning('musicbrainz')" x-cloak>läuft – Fortschritt unten, „Anhalten“ möglich</span>
            </div>
        </section>
        <section class="space-y-2 text-sm text-zinc-400">
            <p><span class="text-zinc-300">So funktioniert es:</span> MusicWiz liest die Dumps von MusicBrainz (Interpreten und Veröffentlichungen, zusammen ca. 3 GB)
                direkt aus dem Netz und behält nur Name, Schreibweisen, Typ (Person/Gruppe), Land, Zusatz, Genres, Verweise auf Spotify/Discogs/Wikidata
                und die Zahl der Veröffentlichungen. Auf der Platte wird nichts zwischengespeichert; in der Datenbank sind es danach ca. 1–2 GB.</p>
            <p>Das dauert je nach Leitung und NAS 30–60 Minuten. Bis es fertig ist, gilt der bisherige Stand; „Anhalten“ verwirft den neuen.</p>
            <p>Danach fragen Abgleich und Kombis MusicBrainz nur noch, wenn ein Name lokal nicht gefunden wird (z. B. Tippfehler oder ganz neue Interpreten).
                MusicBrainz veröffentlicht zweimal pro Woche einen neuen Stand – einmal im Monat aktualisieren reicht.</p>
        </section>
    </div>
    @elseif ($tab === 'sonstiges')
    <div class="max-w-3xl px-6 py-6">
        <form wire:submit="saveOther" class="space-y-4">
            @foreach (\App\Support\Settings::OTHER as $key => [$label, $hint])
                <div>
                    <label for="other-{{ $key }}" class="mb-1 block text-sm font-medium text-zinc-300">{{ $label }}</label>
                    <input id="other-{{ $key }}" type="number" inputmode="numeric" wire:model="other.{{ $key }}" class="field w-40 tabular-nums">
                    @if (isset($otherErrors[$key]))<p class="mt-1 text-xs text-red-400">{{ $otherErrors[$key] }}</p>@endif
                    <p class="mt-1 text-xs text-zinc-500">{{ $hint }}</p>
                </div>
            @endforeach
            <div>
                <label for="generic-titles" class="mb-1 block text-sm font-medium text-zinc-300">Allgemeine Titel (Duplikate)</label>
                <textarea id="generic-titles" rows="2" wire:model="genericTitles" class="field"></textarea>
                <p class="mt-1 text-xs text-zinc-500">Kommagetrennt. Lieder mit solchen Titeln („Intro“, auch „Intro 2“) ersetzen sich über Albumgrenzen nur, wenn der akustische Fingerabdruck bestätigt, dass es dieselbe Aufnahme ist.</p>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary">Speichern</button>
                @if ($otherSaved)<span class="text-sm text-emerald-400">{{ $otherSaved }}</span>@endif
            </div>
        </form>
    </div>
    @endif
    </div>
</div>
