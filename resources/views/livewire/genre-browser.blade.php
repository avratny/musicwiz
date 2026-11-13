@php $fmt = fn ($n) => number_format($n, 0, ',', '.'); @endphp
<div @keydown.escape.window="$wire.selected && $wire.close()"
     x-data="{
        open: JSON.parse(localStorage.getItem('mw.genresOpen') ?? '[]'),
        targets: @js($targets),
        searching: @js($visible !== null),
        isOpen(id) { return this.searching || this.open.includes(id) },
        toggle(id) {
            this.open = this.open.includes(id) ? this.open.filter(x => x !== id) : [...this.open, id];
            localStorage.setItem('mw.genresOpen', JSON.stringify(this.open));
        },
     }" x-effect="searching = {{ $visible !== null ? 'true' : 'false' }}">
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-3">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Genres</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} Genres · Hauptgenre › Familie › Style · in die Tags kommt am Ende nur das Hauptgenre</p>
            </div>
            <div class="flex rounded-md border border-zinc-700 p-0.5 text-sm">
                @foreach (['tree' => 'Baum', 'unmapped' => 'Nicht zugeordnet'] as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                            @class(['inline-flex items-center gap-1.5 rounded px-3 py-1 font-medium', 'bg-zinc-700 text-zinc-100' => $tab === $key, 'text-zinc-400 hover:text-zinc-200' => $tab !== $key])>
                        {{ $label }}
                        @isset ($open[$key])
                            <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-amber-500/15 text-amber-300' => $open[$key] > 0, 'text-zinc-500' => $open[$key] === 0])>{{ number_format($open[$key], 0, ',', '.') }}</span>
                        @endisset
                    </button>
                @endforeach
            </div>
            @if ($tab === 'tree')
                <div class="relative min-w-48 flex-1">
                    <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" wire:model.live.debounce.250ms="q" placeholder="Genre oder Synonym suchen …" class="field pl-8">
                </div>
                <form class="flex gap-2" x-data="{ name: '' }" @submit.prevent="$wire.addMain(name); name = ''">
                    <input type="text" x-model="name" placeholder="Neues Hauptgenre" class="field w-44">
                    <button class="btn" :disabled="!name.trim()">Anlegen</button>
                </form>
            @endif
        </div>
    </header>

    {{-- Meldungen (auch wenn kein Flyout offen ist) --}}
    @if ($error || $message)
        <div class="px-6 pt-3">
            <div @class(['rounded-md px-3 py-2 text-sm', 'bg-red-500/15 text-red-300' => $error, 'bg-emerald-500/10 text-emerald-300' => ! $error])>{{ $error ?? $message }}</div>
        </div>
    @endif

    @if ($tab === 'tree')
        <div class="mt-3 text-sm">
            @forelse ($byParent[0] ?? collect() as $node)
                @include('livewire.partials.genre-node', ['node' => $node])
            @empty
                <div class="px-6 py-16 text-center text-zinc-500">Noch keine Genres.</div>
            @endforelse
            @if ($visible !== null && ! $visible)
                <div class="px-6 py-16 text-center text-zinc-500">Nichts gefunden.</div>
            @endif
        </div>
    @else
        <div class="px-6 py-4">
            <p class="mb-3 text-sm text-zinc-500">Genre-Werte aus Tags und Abgleich, die keinem Genre im Baum entsprechen. Zuordnen hängt den Wert als Synonym an – danach gilt er überall.</p>
            <table class="w-full text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="border-b border-zinc-800 py-2">Wert</th>
                        <th class="w-20 border-b border-zinc-800 py-2 text-right">Lieder</th>
                        <th class="w-24 border-b border-zinc-800 py-2 text-right">Interpreten</th>
                        <th class="border-b border-zinc-800 py-2 pl-6">Vorschläge</th>
                        <th class="w-72 border-b border-zinc-800 py-2 pl-4"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($unmapped as $u)
                        @if (isset($u['done']))
                            {{-- gerade erledigt: bleibt grün mit Haken an seinem Platz --}}
                            <tr wire:key="um-done-{{ md5($u['value']) }}" class="bg-emerald-500/10"
                                x-data x-init="$el.animate([{ backgroundColor: 'rgb(16 185 129 / 0.35)' }, { backgroundColor: 'rgb(16 185 129 / 0.10)' }], { duration: 900 })">
                                <td class="border-b border-emerald-500/30 py-2 pr-3 text-emerald-200">
                                    <div class="flex items-center gap-2">
                                        <svg class="size-4 shrink-0 text-emerald-300" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>
                                        <span>{{ $u['value'] }}</span>
                                    </div>
                                </td>
                                <td colspan="4" class="border-b border-emerald-500/30 py-2 pl-6 text-xs text-emerald-300/90">{{ $u['done'] }}</td>
                            </tr>
                            @continue
                        @endif
                        {{-- Klick zeigt sofort „wird übernommen“ (busy), bis die Seite zurückkommt --}}
                        <tr wire:key="um-{{ md5($u['value']) }}" class="align-top transition-opacity" x-data="{ busy: false }"
                            :class="busy && 'pointer-events-none opacity-40'">
                            <td class="border-b border-zinc-800/70 py-2 pr-3 text-zinc-200">
                                <div class="flex flex-wrap items-center gap-2">
                                    {{-- ohne Rückfrage: unten unter „Wird entfernt“ zurücknehmbar --}}
                                    <button class="whitespace-nowrap rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-200 hover:bg-amber-500/25" wire:click="ignore(@js($u['value']))" @click="busy = true"
                                            title=""Nicht zuordnen, sondern aus den Liedern entfernen – bleibt kein Genre, kommt das Hauptgenre des Interpreten hinein (zurücknehmbar unter „Wird entfernt“)">zu breit – entfernen</button>
                                    <span>{{ $u['value'] }}</span>
                                </div>
                            </td>
                            <td class="border-b border-zinc-800/70 py-2 text-right tabular-nums text-zinc-400">{{ $u['tracks'] ?: '' }}</td>
                            <td class="border-b border-zinc-800/70 py-2 text-right tabular-nums text-zinc-400">{{ $u['artists'] ?: '' }}</td>
                            <td class="border-b border-zinc-800/70 py-2 pl-6">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($u['suggestions'] as $sug)
                                        <button class="rounded-full bg-accent/10 px-2.5 py-0.5 text-xs text-accent hover:bg-accent/20" title="{{ $sug['reason'] }} – „{{ $u['value'] }}“ als Synonym hier einhängen"
                                                wire:click="assign(@js($u['value']), {{ $sug['id'] }})" @click="busy = true">→ {{ $sug['path'] }}</button>
                                    @empty
                                        <span class="text-xs text-zinc-600">keine</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="border-b border-zinc-800/70 py-2 pl-4" x-data="{ pick: false }">
                                {{-- anderes Genre: dieselbe Volltextsuche wie unter Sonstiges › Genre, ohne Umweg über den Server --}}
                                <div x-show="pick" x-cloak x-data="genrePicker(null, null, { take: (r) => { busy = true; $wire.assign(@js($u['value']), r.id) } })"
                                     @keydown="keys($event)" @click.outside="open = false" class="relative">
                                    <input type="text" x-ref="input" x-model="q" @focus="open = true" @input="open = true; active = 0; place()" @keydown.escape="pick = false"
                                           x-effect="pick && $nextTick(() => $refs.input.focus())" placeholder="Genre suchen …" autocomplete="off" class="field w-full py-1 text-xs">
                                    @include('livewire.partials.genre-results')
                                </div>
                                <div x-show="!pick" class="flex items-center gap-3">
                                    <button class="text-xs font-medium text-accent hover:underline" @click="pick = true">anderes Genre …</button>
                                    <button class="text-xs font-medium text-accent hover:underline" @click="$dispatch('new-genre', { name: @js($u['value']), ctx: { value: @js($u['value']) } })">+ neues Genre …</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-16 text-center text-zinc-500">Alles zugeordnet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <script type="application/json" id="genre-index">@json($genreIndex)</script>

            @include('livewire.partials.new-genre-dialog', [
                'submit' => '$wire.createAndAssign(ctx.value, name, parent)',
                'hint' => "'für „' + ctx.value + '“ – wird angelegt und mit dem Wert verknüpft (heißt es anders, wird der Wert sein Synonym).'",
            ])
                </form>
            </div>

            @if ($ignores->isNotEmpty())
                <div class="mt-8">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Wird entfernt (zu breit)</div>
                    <p class="mb-2 text-xs text-zinc-500">Diese Werte werden aus den Liedern entfernt – auch nach jedem Einlesen. Bleibt kein Genre, kommt das Hauptgenre des Interpreten hinein.</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($ignores as $ig)
                            <span class="inline-flex items-center gap-2 rounded-full bg-amber-500/10 py-1 pl-3 pr-2 text-sm text-amber-200" wire:key="ig-{{ $ig->id }}">
                                {{ $ig->value }}
                                <button class="text-xs text-zinc-400 hover:text-zinc-100" wire:click="unignore({{ $ig->id }})" title="Wieder unter „Nicht zugeordnet“ zeigen (angepasste Lieder bleiben)">zurücknehmen</button>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Flyout: Genre bearbeiten --}}
    @if ($genre)
        <aside class="fixed right-0 top-0 z-40 flex flex-col border-l border-zinc-800 bg-zinc-900 shadow-2xl" wire:key="genre-flyout-{{ $genre->id }}" x-data="flyoutPanel" :style="style">
            <div class="absolute inset-y-0 -left-1 z-10 w-2 cursor-col-resize transition-colors hover:bg-accent/40" :class="resizing && 'bg-accent/60'"
                 @mousedown.prevent="startResize($event)" @dblclick="resetWidth()" title="Breite ziehen (Doppelklick: Standard)"></div>
            <div class="flex items-start gap-3 border-b border-zinc-800 p-5">
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-zinc-500">{{ \App\Models\Genre::LEVELS[$genre->level] }} · Ebene {{ $genre->level }}</div>
                    <div class="truncate text-xl font-semibold">{{ $genre->name }}</div>
                    <div class="truncate text-xs text-zinc-500">{{ $path }}</div>
                </div>
                <button class="rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" wire:click="close" title="Schließen (Esc)">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="flex-1 space-y-6 overflow-y-auto p-5">
                @if ($error)
                    <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
                @elseif ($message)
                    <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
                @endif

                <form wire:submit="save" class="space-y-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Name</span>
                        <input type="text" wire:model="form.name" class="field">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-zinc-500">Eingeordnet unter</span>
                        <select wire:model="form.parent_id" class="field">
                            <option value="">— Hauptgenre (Ebene 1) —</option>
                            @foreach ($parents as $p)
                                <option value="{{ $p['id'] }}">{{ $p['path'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-zinc-500">Beatport-Genre</span>
                            <input type="text" wire:model="form.beatport" class="field">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-zinc-500">Discogs-Genre</span>
                            <input type="text" wire:model="form.discogs" class="field">
                        </label>
                    </div>
                    <button type="submit" class="btn-primary">Speichern</button>
                </form>

                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Synonyme / Schreibweisen</div>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse ($genre->synonyms as $s)
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-800 py-1 pl-3 pr-1 text-sm" wire:key="syn-{{ $s->id }}">
                                {{ $s->synonym }}
                                <button class="rounded-full p-0.5 text-zinc-500 hover:bg-zinc-700 hover:text-zinc-200" wire:click="removeSynonym({{ $s->id }})" title="Entfernen">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </span>
                        @empty
                            <span class="text-sm text-zinc-500">Noch keine.</span>
                        @endforelse
                    </div>
                    <form wire:submit="addSynonym" class="mt-2 flex gap-2">
                        <input type="text" wire:model="newSynonym" placeholder="Synonym hinzufügen" class="field">
                        <button class="btn shrink-0">Hinzufügen</button>
                    </form>
                </div>

                @if ($genre->level < 3)
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $genre->level === 1 ? 'Familien' : 'Styles' }} ({{ $genre->children->count() }})</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($genre->children as $c)
                                <button class="rounded-full bg-zinc-800 px-3 py-1 text-sm text-zinc-300 hover:bg-zinc-700" wire:click="open({{ $c->id }})">{{ $c->name }}</button>
                            @endforeach
                        </div>
                        <form wire:submit="addChild" class="mt-2 flex gap-2">
                            <input type="text" wire:model="newChild" placeholder="{{ $genre->level === 1 ? 'Familie' : 'Style' }} anlegen" class="field">
                            <button class="btn shrink-0">Anlegen</button>
                        </form>
                    </div>
                @endif

                <div>
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Verknüpfte Interpreten ({{ $linkedArtists->count() }}{{ $linkedArtists->count() >= 40 ? '+' : '' }})</div>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse ($linkedArtists as $a)
                            <a href="{{ route('artists', ['q' => $a->name]) }}" wire:navigate class="rounded-full bg-zinc-800 px-3 py-1 text-sm text-zinc-300 hover:bg-zinc-700">{{ $a->name }}</a>
                        @empty
                            <span class="text-sm text-zinc-500">Keine.</span>
                        @endforelse
                    </div>
                </div>

                <div class="border-t border-zinc-800 pt-4">
                    <button class="text-sm text-red-400 hover:underline disabled:opacity-40" wire:click="delete" @disabled($genre->children->isNotEmpty())
                            data-confirm-title="Genre löschen" data-confirm-ok="Löschen" data-confirm-danger data-confirm="„{{ $genre->name }}“ löschen? Verknüpfungen zu Interpreten fallen weg.">Genre löschen</button>
                    @if ($genre->children->isNotEmpty())<span class="ml-2 text-xs text-zinc-500">erst die Untergenres entfernen</span>@endif
                </div>
            </div>
        </aside>
    @endif
</div>
