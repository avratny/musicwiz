{{-- Interpreten › Ähnlich: je Gruppe ähnliche Schreibweisen; die oberste Karte ist per Tastatur bedienbar --}}
@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/[\s\-&,.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
    $hue = fn (string $name) => crc32($name) % 360;
@endphp
<div>
<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-4">
        <div class="mr-2">
            <h1 class="text-lg font-semibold leading-tight">Ähnliche Schreibweisen</h1>
            <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Gruppe' : 'Gruppen' }} – sicherste zuerst</p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500">
            <span><kbd class="kbd">1</kbd>–<kbd class="kbd">9</kbd> richtige Schreibweise</span>
            <span><kbd class="kbd">Enter</kbd> Fazit übernehmen</span>
            <span><kbd class="kbd">Z</kbd> zusammenführen</span>
            <span><kbd class="kbd">V</kbd> verschieden</span>
            <span><kbd class="kbd">S</kbd> später</span>
        </div>
    </div>
</header>

<div class="max-w-5xl space-y-4 px-6 py-5">
    @if ($error)
        <div class="rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
    @elseif ($message)
        <div class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    @forelse ($groups as $g)
        <section wire:key="similar-{{ $g['key'] }}"
                 x-data="similarGroup(@js(collect($g['members'])->map(fn ($m) => ['id' => $m['artist']->id, 'name' => $m['artist']->name])->all()), @js($loop->first), @js($g['key']), @js($g['verdict']))"
                 @keydown.window="key($event)"
                 @class(['overflow-hidden rounded-lg border bg-zinc-900', 'border-accent/50 ring-1 ring-accent/30' => $loop->first, 'border-zinc-800' => ! $loop->first])>
            <div class="flex items-center gap-3 border-b border-zinc-800 px-4 py-2 text-xs">
                <span @class(['rounded-full px-2 py-0.5 font-medium',
                              'bg-emerald-500/15 text-emerald-400' => $g['level'] === \App\Support\ArtistMatcher::CASE,
                              'bg-sky-500/15 text-sky-400' => $g['level'] === \App\Support\ArtistMatcher::CHARS,
                              'bg-violet-500/15 text-violet-300' => $g['level'] === \App\Support\ArtistMatcher::MUSICBRAINZ,
                              'bg-amber-500/15 text-amber-400' => $g['level'] === \App\Support\ArtistMatcher::TYPO])>{{ \App\Support\ArtistMatcher::LEVELS[$g['level']] }}</span>
                <span class="text-zinc-500">{{ count($g['members']) }} Schreibweisen</span>
                @if ($loop->first)<span class="ml-auto text-accent">← Tastatur</span>@endif
            </div>
            {{-- Fazit: Empfehlung mit Begründung --}}
            @php $v = $g['verdict']; @endphp
            @include('livewire.partials.verdict', ['reasons' => $v['reasons'], 'confidence' => $v['confidence'],
                'label' => $v['action'] === 'merge' ? 'Zusammenführen'.($v['name'] ? ' als „'.$v['name'].'“' : '') : ($v['action'] === 'distinct' ? 'Verschieden' : null)])

            @foreach ($g['members'] as $j => $m)
                @php $a = $m['artist']; @endphp
                <div class="flex cursor-pointer items-center gap-3 border-b border-zinc-800/60 px-4 py-2.5" @click="pick({{ $a->id }})"
                     :class="{ 'bg-accent/10': target === {{ $a->id }}, 'hover:bg-zinc-800/50': target !== {{ $a->id }}, 'opacity-45': !checked.includes({{ $a->id }}) }">
                    <input type="checkbox" class="size-4 accent-[var(--color-accent)]" :checked="checked.includes({{ $a->id }})" :disabled="target === {{ $a->id }}"
                           @click.stop="toggle({{ $a->id }})" title="Mitnehmen (abgewählt: bleibt ein eigener Interpret)">
                    <span class="w-5 shrink-0 text-center text-xs tabular-nums text-zinc-500">{{ $j < 9 ? $j + 1 : '' }}</span>
                    <span class="flex size-4 shrink-0 items-center justify-center rounded-full border"
                          :class="target === {{ $a->id }} ? 'border-accent' : 'border-zinc-600'" title="Richtige Schreibweise">
                        <span class="size-2 rounded-full bg-accent" x-show="target === {{ $a->id }}"></span>
                    </span>
                    @if ($a->image)
                        <img src="{{ route('artist.image', [$a->id, 64]) }}?v={{ $a->updated_at?->timestamp }}" loading="lazy" alt="" class="size-9 shrink-0 rounded-full bg-zinc-800 object-cover" onerror="this.style.visibility='hidden'">
                    @else
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white/90" style="background: hsl({{ $hue($a->name) }} 35% 30%)">{{ $initials($a->name) }}</div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            {{-- Unterschied zur gewählten Schreibweise markiert --}}
                            <span class="truncate font-medium whitespace-pre" title="{{ $a->name }}"><span x-text="parts({{ $j }})[0]">{{ $a->name }}</span><mark class="rounded-sm bg-amber-400/25 px-px text-amber-200" x-text="parts({{ $j }})[1]"></mark><span x-text="parts({{ $j }})[2]"></span></span>
                            @if ($a->confirmed)
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-400">
                                    <svg class="size-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>bestätigt</span>
                            @endif
                            <span class="shrink-0 text-[11px] text-zinc-500" x-show="!checked.includes({{ $a->id }})" x-cloak>bleibt eigener Interpret</span>
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-zinc-500">
                            @foreach ($m['samples'] as $k => $s)
                                <button class="max-w-64 truncate hover:text-zinc-200" @click.stop="$store.player.play(@js($m['queue']), {{ $k }})"
                                        :class="$store.player.isCurrent({{ $m['queue'][$k]['id'] }}) && 'text-accent'" title="{{ $m['queue'][$k]['title'] }} anhören">♪ {{ $s['album'] }} <span class="text-zinc-600">({{ $s['n'] }})</span></button>
                            @endforeach
                            @if ($m['mb'])
                                <span class="text-violet-300/80" title="Eintrag bei MusicBrainz (lokale Daten)">MusicBrainz: {{ $m['mb'] }}</span>
                            @elseif ($m['mbCandidates'] > 1)
                                <span class="text-amber-300/80" title="mehrere Einträge bei MusicBrainz passen – keine eindeutige Zuordnung">MusicBrainz: mehrdeutig ({{ $m['mbCandidates'] }})</span>
                            @endif
                            @if ($a->aliases->isNotEmpty())
                                <span class="truncate text-zinc-600" title="{{ $a->aliases->pluck('alias')->implode(' · ') }}">auch: {{ $a->aliases->pluck('alias')->implode(' · ') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="w-28 shrink-0 text-right text-xs tabular-nums text-zinc-400">
                        {{ $fmt($m['tracks']) }} {{ $m['tracks'] === 1 ? 'Lied' : 'Lieder' }}<br>
                        <span class="text-zinc-500">{{ $fmt($m['albums']) }} {{ $m['albums'] === 1 ? 'Album' : 'Alben' }}</span>
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center gap-2 bg-zinc-900/60 px-4 py-3">
                <input type="text" x-model="name" @keydown.enter.prevent="merge()" placeholder="Andere Schreibweise …" class="field w-64">
                <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">→ <span class="text-zinc-300" x-text="ref"></span></span>
                <button class="{{ $v['action'] === 'distinct' ? 'btn' : 'btn-primary' }}" @click="merge()" :disabled="busy || (checked.length < 2 && !name.trim())">Zusammenführen</button>
                <button class="{{ $v['action'] === 'distinct' ? 'btn-primary' : 'btn' }}" @click="different()" :disabled="busy">Verschieden</button>
                <button class="btn" @click="later()" :disabled="busy">Später</button>
            </div>
        </section>
    @empty
        <div class="px-6 py-16 text-center text-zinc-500">Keine ähnlichen Schreibweisen – alles aufgeräumt.</div>
    @endforelse

    @if ($more)
        <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr Gruppen</button></div>
    @endif
</div>
</div>
