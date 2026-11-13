{{-- Interpreten › Eindeutig / Wahrscheinlich / Schreibweise: klar zugeordnete Interpreten – bestätigen, Genres und Bild gesammelt übernehmen --}}
@php
    $titles = ['eindeutig' => 'Eindeutig', 'wahrscheinlich' => 'Wahrscheinlich', 'schreibweise' => 'Schreibweise'];
    $subtitles = ['eindeutig' => 'ohne Zweifel zugeordnet', 'wahrscheinlich' => 'wahrscheinlich der Bekannte unter Gleichnamigen', 'schreibweise' => 'sicher zugeordnet, MusicBrainz schreibt den Namen anders'];
    $reasons = ['lied' => 'Lieder bei MusicBrainz gefunden', 'dienste' => 'einziger Eintrag, mehrere Dienste', 'einziger' => 'einziger Eintrag dieses Namens bei MusicBrainz', 'bekannter' => 'bekanntester Gleichnamiger'];
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/[\s\-&,.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
    $hue = fn (string $name) => crc32($name) % 360;
@endphp
<div>
<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-4">
        <div class="mr-2">
            <h1 class="text-lg font-semibold leading-tight">{{ $titles[$level] }}</h1>
            <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Interpret' : 'Interpreten' }} {{ $subtitles[$level] }}
                @if ($pendingLookup) · {{ $fmt($pendingLookup) }} warten noch auf den Abgleich @endif</p>
        </div>
        @if ($error)
            <div class="rounded-md bg-red-500/15 px-3 py-1.5 text-sm text-red-300">{{ $error }}</div>
        @elseif ($message)
            <div class="flex items-center gap-2 rounded-md bg-emerald-500/15 px-3 py-1.5 text-sm text-emerald-300" wire:key="msg-{{ md5($message) }}"
                 x-data x-init="$el.animate([{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }], { duration: 250 })">
                <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>{{ $message }}</div>
        @endif
        @if ($level === 'wahrscheinlich' && $evidenceOpen)
            <button class="btn ml-auto" wire:click="checkRecordings" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('lieder')"
                    title="Bei MusicBrainz nach Aufnahmen mit den eigenen Titeln suchen – zeigt, welcher Gleichnamige gemeint ist (etwa 1 Abfrage je Sekunde)">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                Lieder prüfen <span class="text-xs text-zinc-500">({{ $fmt($evidenceOpen) }} offen)</span>
            </button>
        @endif
        @if ($total)
            <button @class(['btn-primary', 'ml-auto' => ! ($level === 'wahrscheinlich' && $evidenceOpen)]) wire:click="confirmAll" @click="$store.activity.kick()" :disabled="$store.activity.isRunning('eindeutig')"
                    data-confirm-title="Interpreten bestätigen" data-confirm-ok="Bestätigen"
                    data-confirm="{{ $chosen }} Interpreten {{ $level === 'schreibweise' ? 'umbenennen (Tags der Lieder), bestätigen' : 'bestätigen' }} und Genres sowie Bilder übernehmen?">
                {{ $chosen === $total ? 'Alle '.$fmt($total).' übernehmen' : $fmt($chosen).' von '.$fmt($total).' übernehmen' }}
            </button>
        @endif
    </div>
</header>

<div class="max-w-5xl space-y-3 px-6 py-5">
    @if (! $available)
        <div class="rounded-lg border border-zinc-800 bg-zinc-900 p-5 text-sm text-zinc-400">
            Dafür braucht es die lokalen MusicBrainz-Daten – nur so ist sicher, dass kein anderer Interpret genauso heißt.
            <a href="{{ route('settings', ['tab' => 'musicbrainz']) }}" wire:navigate class="text-accent hover:underline">Einstellungen › MusicBrainz</a>
        </div>
    @else
        @if ($level === 'wahrscheinlich')
            <p class="text-xs text-amber-300/80">Es gibt bei MusicBrainz mehrere Interpreten dieses Namens – vorgeschlagen ist der mit Abstand bekannteste. Bitte durchsehen und
                abwählen, wo ein kleiner Namensvetter gemeint sein kann. „Lieder prüfen“ klärt das über die eigenen Titel: Belegte wandern nach „Eindeutig“, widerlegte verschwinden.</p>
        @elseif ($level === 'schreibweise')
            <p class="text-xs text-amber-300/80">Je Zeile wählen: die Schreibweise von MusicBrainz, „So lassen“ oder – nach „Claude fragen“ – Claudes Vorschlag. Umbenennen
                ändert die Lieder (als Tag-Änderung), der bisherige Name bleibt als Schreibweise. „Alle übernehmen“ nimmt Claudes Antwort, wo es eine gibt, sonst MusicBrainz.</p>
        @endif
        <p class="text-xs text-zinc-500">Übernommen wird: bestätigen und die IDs von MusicBrainz, Spotify, Discogs (wie „Diesen nehmen“), die Genres aus dem Treffer
            und ein Porträt (Fanart.tv → TheAudioDB → Spotify → Discogs), wenn der Interpret noch kein Bild hat.@if ($level !== 'schreibweise') Die Lieder ändern sich dabei nicht.@endif</p>

        @forelse ($rows as $c)
            @if (isset($c['done']))
                {{-- gerade übernommen: bleibt mit Haken stehen --}}
                <div wire:key="clear-done-{{ $c['id'] }}" class="flex items-center gap-4 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3"
                     x-data x-init="$el.animate([{ backgroundColor: 'rgb(16 185 129 / 0.35)' }, { backgroundColor: 'rgb(16 185 129 / 0.10)' }], { duration: 900 })">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-300">
                        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-emerald-200">{{ $c['done']['name'] }}</div>
                        <div class="text-xs text-emerald-300/80">übernommen – {{ $c['done']['summary'] }}</div>
                    </div>
                </div>
                @continue
            @endif
            @php $a = $c['artist']; $off = in_array($a->id, $skip, true); @endphp
            <div wire:key="clear-{{ $a->id }}" @class(['flex items-center gap-4 rounded-lg border border-zinc-800 bg-zinc-900 px-4 py-3', 'opacity-45' => $off])>
                <input type="checkbox" class="size-4 accent-[var(--color-accent)]" @checked(! $off) wire:click="toggle({{ $a->id }})" title="Bei „Alle übernehmen“ mitnehmen">
                @if ($c['image'])
                    <img src="{{ route('artist.preview', $c['image']->id) }}?v={{ substr(md5((string) ($c['image']->preview_url ?: $c['image']->url)), 0, 8) }}" alt=""
                         class="size-12 shrink-0 rounded-full bg-zinc-800 object-cover" title="Vorgeschlagenes Bild ({{ \App\Models\ArtistSource::SERVICES[$c['image']->source->service] ?? '' }})">
                @elseif ($a->image)
                    <img src="{{ route('artist.image', [$a->id, 64]) }}?v={{ $a->updated_at?->timestamp }}" alt="" class="size-12 shrink-0 rounded-full bg-zinc-800 object-cover" title="Hat schon ein Bild">
                @else
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white/90" style="background: hsl({{ $hue($a->name) }} 35% 30%)"
                         title="Kein Bild gefunden">{{ $initials($a->name) }}</div>
                @endif
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <span class="truncate font-medium">{{ $a->name }}</span>
                        @if ($c['rename'])<span class="shrink-0 text-zinc-500">→</span><span class="truncate font-medium text-amber-200">{{ $c['rename'] }}</span>@endif
                        <span class="shrink-0 text-xs text-zinc-500">{{ $fmt($c['tracks']) }} {{ $c['tracks'] === 1 ? 'Lied' : 'Lieder' }}</span>
                    </div>
                    <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                        <span class="text-violet-300/80">MusicBrainz: {{ \App\Support\SimilarVerdict::describe($c['mb']) }}</span>
                        <span class="text-zinc-500">{{ implode(' · ', array_map(fn ($s) => \App\Models\ArtistSource::SERVICES[$s] ?? $s, array_keys($c['group']['ids']))) }}</span>
                        <span @class(['text-emerald-300/80' => $c['reason'] === 'lied', 'text-zinc-500' => $c['reason'] !== 'lied'])>{{ $reasons[$c['reason']] ?? '' }}</span>
                    </div>
                    @if ($c['claude'])
                        <div class="mt-1 flex items-start gap-1.5 text-xs">
                            <svg class="mt-0.5 size-3.5 shrink-0 text-orange-300" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/></svg>
                            @if ($c['claude']->error)
                                <span class="text-red-300">Claude: {{ $c['claude']->error }}</span>
                            @else
                                <span class="text-orange-200">Claude: „{{ $c['claude']->spelling }}“
                                    {{ ['ours' => '(so lassen)', 'musicbrainz' => '(wie MusicBrainz)', 'other' => '(weder noch)', 'unsure' => '(unsicher – ein anderer Name, nur einzeln wählbar)'][$c['claude']->source] ?? '' }}
                                    <span class="text-zinc-400">– {{ $c['claude']->reason }}</span></span>
                            @endif
                        </div>
                    @endif
                    @if ($c['rival'])
                        <div class="mt-0.5 text-xs text-zinc-500">
                            {{ $fmt($c['rival']['own']) }} Veröffentlichungen – {{ $c['rival']['count'] === 1 ? 'der andere' : 'der nächste von '.$c['rival']['count'].' anderen' }}:
                            {{ $c['rival']['mb'] ? \App\Support\SimilarVerdict::describe($c['rival']['mb']) : 'ohne MusicBrainz-Eintrag' }}, {{ $fmt($c['rival']['releases']) }} Veröffentlichungen
                            ({{ implode(' · ', array_map(fn ($s) => \App\Models\ArtistSource::SERVICES[$s] ?? $s, $c['rival']['services'])) }})
                        </div>
                    @endif
                    @if ($c['genres'])
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($c['genres'] as $genre)<span class="rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] text-zinc-300">{{ $genre }}</span>@endforeach
                        </div>
                    @endif
                </div>
                @if ($c['rename'])
                    {{-- Schreibweise: zur Wahl stehen unsere, die von MusicBrainz und ggf. Claudes – Claudes Wahl hervorgehoben --}}
                    @php
                        $pick = $c['claude'] && ! $c['claude']->error && $c['claude']->source !== 'unsure' ? $c['claude']->spelling : null;
                        $options = array_values(array_unique(array_filter([$c['rename'], $a->name, $pick ?? $c['claude']?->spelling])));
                    @endphp
                    <div class="flex shrink-0 flex-col items-stretch gap-1.5">
                        @foreach ($options as $option)
                            <button @class(['btn max-w-56 justify-start', 'ring-1 ring-orange-300/60' => $option === $pick]) wire:click="confirmAs({{ $a->id }}, @js($option))" wire:loading.attr="disabled"
                                    title="{{ $option === $a->name ? 'Name so lassen und bestätigen' : 'Umbenennen in „'.$option.'“ und bestätigen' }}">
                                <span class="truncate">{{ $option === $a->name ? 'So lassen' : '„'.$option.'“' }}</span>
                                @if ($option === $pick)<span class="text-[10px] uppercase tracking-wide text-orange-300">Claude</span>@endif
                            </button>
                        @endforeach
                        @if ($claudeAvailable)
                            <button class="btn justify-start text-xs" wire:click="askClaude({{ $a->id }})" wire:loading.attr="disabled" wire:target="askClaude({{ $a->id }})"
                                    title="Claude fragen, welche Schreibweise stimmt">
                                <span wire:loading.remove wire:target="askClaude({{ $a->id }})">{{ $c['claude'] ? 'Claude erneut fragen' : 'Claude fragen' }}</span>
                                <span wire:loading wire:target="askClaude({{ $a->id }})" class="inline-flex items-center gap-1.5"><svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3"/></svg>fragt …</span>
                            </button>
                        @endif
                    </div>
                @else
                    <button class="btn shrink-0" wire:click="confirm({{ $a->id }})" wire:loading.attr="disabled" wire:target="confirm({{ $a->id }})" title="Sofort übernehmen">
                        <span wire:loading.remove wire:target="confirm({{ $a->id }})">Übernehmen</span>
                        <span wire:loading wire:target="confirm({{ $a->id }})" class="inline-flex items-center gap-1.5">
                            <svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3"/></svg>übernimmt …</span>
                    </button>
                @endif
            </div>
        @empty
            <div class="px-6 py-16 text-center text-zinc-500">
                Gerade kein Interpret in dieser Liste.@if ($pendingLookup) Nach dem Abgleich ({{ $fmt($pendingLookup) }} offen) kommen meist welche dazu.@endif
            </div>
        @endforelse

        @if ($more)
            <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
        @endif
    @endif
</div>
</div>
