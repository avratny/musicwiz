<div class="flex h-full flex-col overflow-hidden">
    <x-page-header title="Künstler" subtitle="Jeder Künstler genau einmal – mit richtiger Schreibweise, Varianten und Bild.">
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
            @include('livewire.partials.artists.list')
            <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                @if ($artist)
                    @include('livewire.partials.artists.detail')
                @else
                    <div class="grid flex-1 place-items-center text-center text-zinc-500">
                        <div>
                            <p>Künstler links wählen.</p>
                            <p class="mt-1 text-sm">Filter oben helfen beim Durchgehen – z. B. „Noch nicht geprüft“ oder „Ohne Bild“.</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @include('livewire.partials.artists.dialogs')
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
