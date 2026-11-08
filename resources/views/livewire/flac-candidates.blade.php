<div class="flex h-full flex-col overflow-hidden">
    <x-page-header title="FLAC-Kandidaten" subtitle="Lib-Alben nur als MP3 bzw. unvollständig – bei Qobuz suchen und komplett als FLAC kaufen. Gekauftes über die Inbox einordnen: „bessere Version behalten“ ersetzt die alten Dateien." />

    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-zinc-800 px-6 py-2">
        <div class="flex rounded-lg border border-zinc-700 p-0.5 text-sm">
            @foreach (['alle' => 'Nur MP3', 'incomplete' => 'Unvollständig', 'watch' => 'Merkliste', 'hidden' => 'Ausgeblendet'] as $k => $label)
                <button wire:click="$set('show', '{{ $k }}')" class="rounded-md px-3 py-1 transition {{ $show === $k ? 'bg-zinc-700 text-white' : 'text-zinc-400 hover:text-zinc-200' }}">{{ $label }} <span class="text-xs text-zinc-500">{{ $counts[$k] }}</span></button>
            @endforeach
        </div>
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Interpret oder Album …" class="input-sm w-60">
        <select wire:model.live="genre" class="input-sm w-auto max-w-56">
            <option value="">Alle Genres</option>
            @foreach ($genres as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
        </select>
        <select wire:model.live="minRating" class="input-sm w-auto">
            <option value="0">Alle Bewertungen</option>
            @foreach ([5 => '★★★★★', 4 => 'ab ★★★★', 3 => 'ab ★★★'] as $v => $label)<option value="{{ $v }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="sort" class="input-sm w-auto">
            <option value="rating">Beste Bewertung zuerst</option>
            <option value="bitrate">Niedrigste Bitrate zuerst</option>
            <option value="artist">Nach Interpret</option>
            <option value="year">Nach Jahr</option>
        </select>
        @if ($show !== 'incomplete')
        <button wire:click="$toggle('samplers')" class="rounded-lg border px-3 py-1 text-sm transition {{ $samplers ? 'border-violet-500/60 bg-violet-500/15 text-violet-200' : 'border-zinc-700 text-zinc-400 hover:text-zinc-200' }}">mit Samplern</button>
        @endif
        <span class="ml-auto text-xs text-zinc-500">{{ $total }} {{ $total === 1 ? 'Album' : 'Alben' }}</span>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-3">
        <div class="space-y-1">
            @forelse ($items as $c)
                @php $a = $c['album']; @endphp
                <div wire:key="fc{{ $a->id }}" class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-900/60">
                    <x-cover :url="$a->coverUrl(96)" class="size-11" />
                    <a href="{{ $a->editUrl() }}" wire:navigate class="min-w-0 flex-1">
                        <div class="truncate text-sm text-zinc-100" title="{{ $a->title }}">{{ $a->title }}{{ $a->year ? ' ('.$a->year.')' : '' }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ $a->is_compilation ? 'Sampler' : ($a->folder_artist ?: $a->albumartist) }}{{ ($a->genres[0] ?? null) ? ' · '.$a->genres[0] : '' }} · {{ $c['tracks'] }} Titel</div>
                        @if ($c['missing'])
                            <div class="truncate text-xs text-amber-300" title="{{ $c['missing'] }}">unvollständig: {{ str_replace("\n", ' · ', $c['missing']) }}</div>
                        @endif
                    </a>
                    <x-stars :value="$c['rating']" />
                    <span class="w-24 text-right text-xs tabular-nums {{ ($c['bitrate'] ?? 0) < 192 ? 'text-amber-300' : 'text-zinc-400' }}" title="durchschnittliche Bitrate">{{ $c['bitrate'] ? $c['bitrate'].' kbit/s' : '–' }}</span>
                    @if ($c['state'] === 'watch')
                        <button wire:click="unmark({{ $a->id }})" class="badge-violet hover:bg-violet-500/30" title="Von der Merkliste nehmen">★ gemerkt</button>
                    @elseif ($c['state'] === 'hidden')
                        <button wire:click="unmark({{ $a->id }})" class="btn-ghost btn-sm">wieder zeigen</button>
                    @else
                        <button wire:click="mark({{ $a->id }}, 'watch')" class="btn-ghost btn-sm" title="Auf die Merkliste">merken</button>
                        <button wire:click="mark({{ $a->id }}, 'hidden')" class="btn-ghost btn-sm text-zinc-500" title="Gibt es nicht als FLAC bzw. nicht gewünscht">ausblenden</button>
                    @endif
                    <span class="flex items-center gap-1" title="Bei Qobuz suchen (öffnet einen neuen Tab)">
                        <span class="text-xs text-zinc-500">Qobuz:</span>
                        @if ($artistUrl = $candidates->qobuzArtistUrl($a))
                            <a href="{{ $artistUrl }}" target="_blank" rel="noopener" class="btn-ghost btn-sm" title="Alle Alben von {{ $a->folder_artist ?: $a->albumartist }}">Interpret ↗</a>
                        @endif
                        <a href="{{ $candidates->qobuzUrl($a) }}" target="_blank" rel="noopener" class="btn-primary btn-sm" title="Interpret und Album">Komplett ↗</a>
                    </span>
                </div>
            @empty
                <p class="py-10 text-center text-zinc-500">{{ ['alle' => 'Keine Kandidaten – alles verlustfrei (oder alles ausgeblendet).', 'incomplete' => 'Keine unvollständigen Alben.'][$show] ?? 'Hier ist nichts.' }}</p>
            @endforelse
        </div>
        @if ($total > $items->count())
            <div class="mt-4 flex items-center gap-3">
                <button wire:click="loadMore" class="btn-ghost">Mehr laden</button>
                <span class="text-sm text-zinc-500">noch {{ $total - $items->count() }}</span>
            </div>
        @endif
    </div>
</div>
