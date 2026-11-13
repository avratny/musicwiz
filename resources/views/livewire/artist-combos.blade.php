{{-- Interpreten › Kombis: „Beam & Yanou“ trennen oder ganz lassen, feat. aus dem Titel holen, Entscheidungen verwalten --}}
@php $fmt = fn ($n) => number_format($n, 0, ',', '.'); @endphp
<div>
<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-4">
        <div class="mr-2">
            <h1 class="text-lg font-semibold leading-tight">Kombis</h1>
            <p class="text-xs text-zinc-500">feat., vs., „;“ und „ / “ werden automatisch getrennt – &amp;, und, Komma, x, with erst nach Entscheidung</p>
        </div>
        <div class="flex rounded-md border border-zinc-700 p-0.5 text-sm">
            @foreach (\App\Livewire\ArtistCombos::TABS as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')"
                        @class(['rounded px-3 py-1 font-medium', 'bg-zinc-700 text-zinc-100' => $tab === $key, 'text-zinc-400 hover:text-zinc-200' => $tab !== $key])>
                    {{ $label }} <span class="text-xs tabular-nums text-zinc-500">{{ $fmt($counts[$key]) }}</span></button>
            @endforeach
        </div>
        @if ($tab === 'offen')
            <button class="btn" wire:click="check" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('combos')"
                    title="MusicBrainz, Spotify, Discogs und TheAudioDB nach dem ganzen Namen und den Teilen fragen">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                Dienste fragen @if ($unchecked)<span class="text-xs text-zinc-500">({{ $fmt($unchecked) }} {{ $unchecked === 1 ? 'Name' : 'Namen' }} offen)</span>@endif
            </button>
            <div class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500">
                <span><kbd class="kbd">Enter</kbd> Fazit übernehmen</span>
                <span><kbd class="kbd">T</kbd> trennen</span>
                <span><kbd class="kbd">M</kbd> ganz + Mitglieder</span>
                <span><kbd class="kbd">G</kbd> bleibt ganz</span>
                <span><kbd class="kbd">S</kbd> später</span>
            </div>
        @endif
    </div>
</header>

<div class="max-w-5xl space-y-4 px-6 py-5">
    @if ($error)
        <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
    @elseif ($message)
        <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    @if ($tab === 'offen')
        @forelse ($cards as $card)
            @php [$c, $v] = [$card['combo'], $card['verdict']]; @endphp
            <section wire:key="combo-{{ md5($c['name']) }}"
                     x-data="comboCard(@js($c['name']), @js($c['parts']), @js($c['known']), @js($loop->first), @js($v['action']), @js($card['checks'] ?: new stdClass), @js((bool) ($c['group']['parts'] ?? false)))"
                     @keydown.window="key($event)"
                     @class(['overflow-hidden rounded-lg border bg-zinc-900', 'border-accent/50 ring-1 ring-accent/30' => $loop->first, 'border-zinc-800' => ! $loop->first])>
                <div class="flex items-center gap-3 border-b border-zinc-800 px-4 py-2.5">
                    <span class="truncate text-base font-medium" title="{{ $c['name'] }}">{{ $c['name'] }}</span>
                    <span class="shrink-0 text-xs text-zinc-500">{{ $fmt($c['tracks']) }} {{ $c['tracks'] === 1 ? 'Lied' : 'Lieder' }}</span>
                    @if (\App\Support\ArtistCredits::allAlone($c))
                        <span class="shrink-0 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-400">jeder Teil hat eigene Lieder</span>
                    @elseif (count($c['known']) === count($c['parts']))
                        <span class="shrink-0 rounded-full bg-sky-500/15 px-2 py-0.5 text-[11px] font-medium text-sky-400">alle Teile bekannt</span>
                    @endif
                    @if ($loop->first)<span class="ml-auto shrink-0 text-xs text-accent">← Tastatur</span>@endif
                </div>
                {{-- Fazit: Empfehlung mit Begründung --}}
                @include('livewire.partials.verdict', ['reasons' => $v['reasons'], 'confidence' => $v['confidence'],
                    'label' => ['split' => 'Trennen', 'whole' => 'Bleibt ganz', 'members' => 'Bleibt ganz + Mitglieder'][$v['action']] ?? null])
                @if ($c['group'])
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-zinc-800/60 px-4 py-2 text-xs">
                        <span class="mr-1 text-zinc-500">Mitglieder laut MusicBrainz:</span>
                        @foreach ($c['group']['members'] as $m)
                            <span @class(['rounded-full px-2 py-0.5', 'bg-sky-500/15 text-sky-300' => in_array($m['mbid'], array_column($c['group']['parts'], 'mbid'), true),
                                          'bg-zinc-800 text-zinc-400' => ! in_array($m['mbid'], array_column($c['group']['parts'], 'mbid'), true), 'line-through opacity-60' => $m['ended']])
                                  title="{{ $m['ended'] ? 'ehemaliges Mitglied' : 'Mitglied' }}">{{ $m['name'] }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="flex flex-wrap items-center gap-1.5 border-b border-zinc-800/60 px-4 py-2 text-xs">
                    <span class="mr-1 text-zinc-500">als Ganzes:</span>
                    @include('livewire.partials.name-check-badges', ['expr' => 'name'])
                </div>
                <div class="space-y-1.5 px-4 py-3">
                    <template x-for="(part, i) in parts" :key="i">
                        <div class="flex items-center gap-2">
                            <input type="text" x-model="parts[i]" @keydown.enter.prevent="split()" class="field w-72 py-1">
                            <span class="text-xs" :class="!known[part.trim()] ? 'text-zinc-500' : known[part.trim()].alone ? 'text-emerald-400' : 'text-sky-400'"
                                  x-text="ownTracks(part)"></span>
                            @php $memberParts = array_keys($c['group']['parts'] ?? []); @endphp
                            <span x-show="@js($memberParts).includes(part.trim())" class="rounded-full bg-sky-500/15 px-2 py-0.5 text-[11px] font-medium text-sky-300"
                                  title="genau dieses Mitglied – Zahlen über seine MusicBrainz-ID, keine Verwechslung mit Gleichnamigen">Mitglied ✓</span>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs">@include('livewire.partials.name-check-badges', ['expr' => 'part'])</div>
                            <button class="ml-auto rounded p-1 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" @click="parts.splice(i, 1)" x-show="parts.length > 2" title="Teil entfernen">
                                <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                            </button>
                        </div>
                    </template>
                    <button class="text-xs text-zinc-500 hover:text-zinc-200" @click="parts.push('')">+ Teil</button>
                </div>
                <div class="flex flex-wrap items-center gap-2 bg-zinc-900/60 px-4 py-3">
                    <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">Das Interpret-Tag bleibt „{{ $c['name'] }}“ – die Teile kommen in „Interpreten einzeln“ (ARTISTS).</span>
                    <button class="{{ $v['action'] === 'split' || $v['action'] === null ? 'btn-primary' : 'btn' }}" @click="split()" :disabled="busy || parts.filter((p) => p.trim()).length < 2">Trennen</button>
                    <button class="{{ $v['action'] === 'whole' ? 'btn-primary' : 'btn' }}" @click="whole()" :disabled="busy" title="Ist ein eigener Interpret (wird bestätigt)">Bleibt ganz</button>
                    @if ($c['group'] && $c['group']['parts'])
                        <button class="{{ $v['action'] === 'members' ? 'btn-primary' : 'btn' }}" @click="withMembers()" :disabled="busy"
                                title="Gruppe bleibt Interpret, die Mitglieder kommen mit in „Interpreten einzeln“ – das Lied findet sich auch bei ihnen">Ganz + Mitglieder</button>
                    @endif
                    <button class="btn" @click="later()" :disabled="busy">Später</button>
                </div>
            </section>
        @empty
            <div class="px-6 py-16 text-center text-zinc-500">Keine offenen Kombis.</div>
        @endforelse
        @if ($more)
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr Kombis</button></div>
        @endif

    @elseif ($tab === 'titel')
        @if ($titles)
            <div class="flex items-center gap-3">
                <p class="flex-1 text-sm text-zinc-400">„Song (feat. X)“ von „A“ wird zu „Song“ von „A feat. X“.</p>
                <button class="btn-primary" wire:click="moveTitleGuests(@js(array_map(fn ($t) => $t['track']->id, $titles)))"
                        data-confirm-title="Lieder anpassen" data-confirm-ok="Anpassen" data-confirm="{{ count($titles) }} Lieder anpassen?">Alle {{ count($titles) }} übernehmen</button>
            </div>
        @endif
        <table class="w-full text-sm">
            <tbody>
                @forelse ($titles as $t)
                    <tr wire:key="title-{{ $t['track']->id }}" class="hover:bg-zinc-900">
                        <td class="border-b border-zinc-800/70 py-2 pr-3">
                            <div class="truncate text-zinc-500 line-through decoration-zinc-600">{{ $t['track']->artist }} – {{ $t['track']->title }}</div>
                            <div class="truncate font-medium">{{ $t['artist'] }} – {{ $t['title'] }}</div>
                        </td>
                        <td class="w-32 border-b border-zinc-800/70 py-2 text-right text-xs text-zinc-500">{{ \App\Support\Locations::LABELS[$t['track']->location] ?? '' }}</td>
                        <td class="w-28 border-b border-zinc-800/70 py-2 text-right">
                            <button class="text-xs font-medium text-accent hover:underline" wire:click="moveTitleGuests([{{ $t['track']->id }}])">Übernehmen</button>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-16 text-center text-zinc-500">Kein feat. im Titel.</td></tr>
                @endforelse
            </tbody>
        </table>

    @else
        <table class="w-full text-sm">
            <tbody>
                @forelse ($decisions as $d)
                    <tr wire:key="decision-{{ $d->id }}" class="hover:bg-zinc-900">
                        <td class="border-b border-zinc-800/70 py-2 pr-3 font-medium">{{ $d->name }}</td>
                        <td class="border-b border-zinc-800/70 py-2 pr-3 text-zinc-400">
                            @if ($d->kind === \App\Models\ArtistSplit::WHOLE)<span class="text-zinc-500">bleibt ganz</span>@elseif ($d->kind === \App\Models\ArtistSplit::MEMBERS)<span class="text-zinc-500">bleibt ganz + Mitglieder:</span> {{ implode(' · ', $d->parts) }}@else{{ implode(' · ', $d->parts) }}@endif
                        </td>
                        <td class="w-28 border-b border-zinc-800/70 py-2 text-right text-xs tabular-nums text-zinc-500">{{ $d->updated_at?->format('d.m.Y') }}</td>
                        <td class="w-28 border-b border-zinc-800/70 py-2 text-right">
                            <button class="text-xs font-medium text-accent hover:underline" wire:click="undo(@js($d->name))" title="Kombi wartet wieder auf eine Entscheidung">Aufheben</button>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-16 text-center text-zinc-500">Noch nichts entschieden.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>
</div>
