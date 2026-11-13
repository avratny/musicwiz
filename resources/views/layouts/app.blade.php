@php
    $counts = \App\Support\Locations::counts() + ['artists' => \App\Models\Artist::count(), 'albums' => \App\Support\SplitAlbums::total(), 'misc' => app(\App\Support\TextRepairs::class)->count(), 'changes' => \App\Models\TagChange::count()];
    $current = request()->route('slug');
    $icons = [
        'inbox' => 'M3 13h4l2 3h6l2-3h4M5 5h14l2 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6z',
        'library' => 'M4 19V5m5 14V5m4 14 4-14 4 14',
        'blackhole' => 'M12 3a9 9 0 1 0 9 9M12 8a4 4 0 1 0 4 4',
        'trash' => 'M4 7h16M10 11v6m4-6v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3',
    ];
@endphp
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'MusicWiz' }}</title>
    <script>window.mwInitial = @js(['activities' => \App\Support\Activity::visible(), 'counts' => $counts]);</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-zinc-950 text-zinc-100 antialiased">
<div class="flex h-full">
    {{-- Navigation links --}}
    <aside class="flex w-56 shrink-0 flex-col border-r border-zinc-800 bg-zinc-900 pb-16" x-data
           :style="`padding-bottom: ${64 + $store.activity.rows * 31}px`">
        <div class="px-5 py-4 text-lg font-semibold tracking-tight">Music<span class="text-accent">Wiz</span></div>
        <nav class="flex flex-col gap-0.5 px-3">
            @foreach (\App\Support\Locations::LABELS as $location => $label)
                @php $slug = \App\Support\Locations::SLUGS[$location]; @endphp
                <a href="{{ route('location', $slug) }}" wire:navigate
                   @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                           'bg-accent/10 text-accent' => $current === $slug,
                           'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => $current !== $slug])>
                    <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $icons[$location] }}"/></svg>
                    <span class="flex-1">{{ $label }}</span>
                    <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs font-semibold tabular-nums text-zinc-400"
                          x-data x-text="($store.activity.counts['{{ $location }}'] ?? 0).toLocaleString('de-DE')">{{ number_format($counts[$location], 0, ',', '.') }}</span>
                </a>
            @endforeach
            <div class="my-2 border-t border-zinc-800"></div>
            <a href="{{ route('artists') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('artists'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('artists')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                <span class="flex-1">Interpreten</span>
                <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs font-semibold tabular-nums text-zinc-400"
                      x-data x-text="($store.activity.counts['artists'] ?? 0).toLocaleString('de-DE')">{{ number_format($counts['artists'], 0, ',', '.') }}</span>
            </a>

            <a href="{{ route('albums') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('albums'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('albums')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/></svg>
                <span class="flex-1">Alben</span>
                <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs font-semibold tabular-nums text-zinc-400"
                      x-data x-text="($store.activity.counts['albums'] ?? 0).toLocaleString('de-DE')">{{ number_format($counts['albums'], 0, ',', '.') }}</span>
            </a>

            <a href="{{ route('genres') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('genres'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('genres')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 3v6m0 0H6v4m6-4h6v4M6 13v2m-2 2h4v4H4zm12 0h4v4h-4z"/></svg>
                <span class="flex-1">Genres</span>
                <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs font-semibold tabular-nums text-zinc-400">{{ number_format(\App\Models\Genre::where('level', 1)->count(), 0, ',', '.') }}</span>
            </a>

            <a href="{{ route('misc') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('misc'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('misc')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                <span class="flex-1">Sonstiges</span>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                      :class="($store.activity.counts['misc'] ?? 0) > 0 ? 'bg-amber-500/15 text-amber-300' : 'bg-zinc-800 text-zinc-400'"
                      x-data x-text="($store.activity.counts['misc'] ?? 0).toLocaleString('de-DE')">{{ number_format($counts['misc'], 0, ',', '.') }}</span>
            </a>

            <div class="mb-1 mt-5 px-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Protokoll</div>
            <a href="{{ route('changes') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('changes'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('changes')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
                <span class="flex-1">Änderungen</span>
                <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs font-semibold tabular-nums text-zinc-400"
                      x-data x-text="($store.activity.counts['changes'] ?? 0).toLocaleString('de-DE')">{{ number_format($counts['changes'], 0, ',', '.') }}</span>
            </a>
            <a href="{{ route('write') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('write'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('write')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                <span class="flex-1">Schreiben</span>
            </a>
        </nav>
        <div class="mt-auto border-t border-zinc-800 p-3">
            <a href="{{ route('settings') }}" wire:navigate
               @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                       'bg-accent/10 text-accent' => request()->routeIs('settings'),
                       'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' => ! request()->routeIs('settings')])>
                <svg class="size-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
                Einstellungen
            </a>
        </div>
    </aside>

    <main class="min-w-0 flex-1 overflow-y-auto pb-28" x-data :style="`padding-bottom: ${96 + $store.activity.rows * 31}px`">
        {{ $slot }}
    </main>
</div>

{{-- unten: Fortschritt und Player --}}
<div class="fixed inset-x-0 bottom-0 z-30" x-data>
    {{-- Hintergrundvorgänge: je Vorgang eine Zeile – ab drei laufenden eine Sammelzeile (Klick: Einzelzeilen auf/zu) --}}
    <div x-cloak x-show="$store.activity.rows" x-transition.opacity class="border-t border-zinc-800 bg-zinc-900/95 backdrop-blur">
        <template x-if="$store.activity.grouped">
            <button type="button" class="flex w-full items-center gap-3 px-4 py-1.5 text-left text-xs text-zinc-400 hover:bg-zinc-800/60"
                    @click="$store.activity.toggle()" :title="$store.activity.expanded ? 'Einzelzeilen zuklappen' : 'Einzelzeilen aufklappen'">
                <svg class="size-3.5 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3"/></svg>
                <span class="shrink-0 text-zinc-300" x-text="`${$store.activity.runningItems.length} Vorgänge laufen`"></span>
                <span class="flex min-w-0 flex-1 items-center gap-4 overflow-hidden" x-show="!$store.activity.expanded">
                    <template x-for="a in $store.activity.runningItems" :key="a.key">
                        <span class="flex shrink-0 items-center gap-1.5">
                            <span class="text-zinc-400" x-text="a.label"></span>
                            <span class="h-1 w-12 overflow-hidden rounded-full bg-zinc-700">
                                <span class="block h-full rounded-full bg-accent"
                                      :class="$store.activity.percent(a) === null && 'w-1/3 animate-pulse'"
                                      :style="$store.activity.percent(a) !== null && `width: ${$store.activity.percent(a)}%`"></span>
                            </span>
                            <span class="tabular-nums text-zinc-500" x-show="$store.activity.percent(a) !== null" x-text="`${$store.activity.percent(a)} %`"></span>
                        </span>
                    </template>
                </span>
                <span class="flex-1" x-show="$store.activity.expanded"></span>
                <svg class="size-3.5 shrink-0 text-zinc-500 transition-transform" :class="$store.activity.expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg>
            </button>
        </template>
        <template x-for="a in $store.activity.shown" :key="a.key">
            <div class="flex items-center gap-3 px-4 py-1.5 text-xs text-zinc-400">
                <template x-if="a.running">
                    <svg class="size-3.5 shrink-0 animate-spin text-accent" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3"/></svg>
                </template>
                <template x-if="!a.running"><span class="w-3.5 shrink-0 text-center text-emerald-400">✓</span></template>
                <span class="shrink-0 text-zinc-300" x-text="a.running ? a.label : a.message"></span>
                <span class="truncate text-zinc-500" x-show="a.running && a.detail" x-text="a.detail"></span>
                <div class="h-1.5 min-w-24 flex-1 overflow-hidden rounded-full bg-zinc-700" x-show="a.running">
                    <div class="h-full rounded-full bg-accent transition-all duration-500"
                         :class="$store.activity.percent(a) === null && 'w-1/3 animate-pulse'"
                         :style="$store.activity.percent(a) !== null && `width: ${$store.activity.percent(a)}%`"></div>
                </div>
                <span class="shrink-0 tabular-nums" x-show="a.running && a.total" x-text="`${(a.done ?? 0).toLocaleString('de-DE')} / ${(a.total ?? 0).toLocaleString('de-DE')}`"></span>
                <button class="shrink-0 rounded px-1.5 py-0.5 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" x-show="a.running && ['lookup', 'combos', 'musicbrainz', 'eindeutig', 'zeichen'].includes(a.key)" @click="$store.activity.stop(a.key)" title="Anhalten">Anhalten</button>
            </div>
        </template>
    </div>

    <div class="flex h-16 items-center gap-4 border-t border-zinc-800 bg-zinc-900 px-4">
        <div class="flex w-72 min-w-0 items-center gap-3">
            <template x-if="$store.player.current">
                <img :src="`/cover/${$store.player.current.cover}/64`" class="cover-link size-11 rounded bg-zinc-800 object-cover" onerror="noCover(this)" alt=""
                     @click="$dispatch('open-cover-picker', { track: $store.player.current.cover })" title="Cover ändern">
            </template>
            <div class="min-w-0">
                <div class="truncate text-sm font-medium" x-text="$store.player.current?.title ?? 'Nichts ausgewählt'"></div>
                <div class="truncate text-xs text-zinc-500" x-text="$store.player.current?.artist ?? ''"></div>
            </div>
        </div>
        <div class="flex flex-1 items-center gap-3">
            <button class="rounded-full p-1.5 text-zinc-400 hover:bg-zinc-800" @click="$store.player.prev()" title="Zurück">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/></svg>
            </button>
            <button class="rounded-full bg-zinc-100 p-2 text-zinc-900 hover:bg-white hover:scale-105 transition" @click="$store.player.toggle()" title="Abspielen/Pause">
                <svg x-show="!$store.player.playing" class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                <svg x-cloak x-show="$store.player.playing" class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg>
            </button>
            <button class="rounded-full p-1.5 text-zinc-400 hover:bg-zinc-800" @click="$store.player.next()" title="Weiter">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M16 6h2v12h-2zM6 18l8.5-6L6 6z"/></svg>
            </button>
            <span class="w-10 text-right text-xs tabular-nums text-zinc-500" x-text="$store.player.format($store.player.time)"></span>
            <input type="range" min="0" step="0.1" class="h-1 flex-1 accent-[var(--color-accent)]"
                   :max="$store.player.duration || 0" :value="$store.player.time" @input="$store.player.seek($event.target.value)">
            <span class="w-10 text-xs tabular-nums text-zinc-500" x-text="$store.player.format($store.player.duration)"></span>
        </div>
        <div class="flex w-40 items-center gap-2 text-zinc-500">
            <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M4 9v6h4l5 5V4L8 9z"/></svg>
            <input type="range" min="0" max="1" step="0.01" class="h-1 flex-1 accent-[var(--color-accent)]"
                   :value="$store.player.volume" @input="$store.player.setVolume(parseFloat($event.target.value))">
        </div>
    </div>
</div>
{{-- Rückfragen (data-confirm am Knopf) – statt Browser-Dialog --}}
<div x-data x-cloak x-show="$store.confirm.open" x-transition.opacity.duration.150ms
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
     @keydown.escape.window="$store.confirm.open && $store.confirm.close()">
    <div class="absolute inset-0" @click="$store.confirm.close()"></div>
    <div class="relative w-full max-w-md rounded-xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl" role="dialog" aria-modal="true"
         x-show="$store.confirm.open" x-transition.scale.95.duration.150ms
         x-effect="$store.confirm.open && $nextTick(() => $refs.ok.focus())">
        <div class="flex items-start gap-4">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-full"
                 :class="$store.confirm.danger ? 'bg-red-500/15 text-red-400' : 'bg-accent/15 text-accent'">
                <svg x-show="!$store.confirm.danger" class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
                <svg x-show="$store.confirm.danger" class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold" x-text="$store.confirm.title"></h2>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-400" x-text="$store.confirm.text"></p>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn" @click="$store.confirm.close()">Abbrechen</button>
            <button type="button" x-ref="ok" @click="$store.confirm.accept()" x-text="$store.confirm.ok"
                    :class="$store.confirm.danger ? 'inline-flex items-center gap-1.5 rounded-md bg-red-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-400' : 'btn-primary'"></button>
        </div>
    </div>
</div>
<livewire:artist-create-modal />
<livewire:cover-picker />
</body>
</html>
