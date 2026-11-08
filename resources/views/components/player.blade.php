{{-- Globaler Player am unteren Rand; erscheint, sobald ein Lied gespielt wird. --}}
<div x-data x-show="$store.player.track" x-cloak x-transition.opacity
     class="flex h-16 shrink-0 items-center gap-4 border-t border-zinc-800 bg-zinc-900/95 px-4 backdrop-blur">
    <audio id="mw-audio" preload="none"
           x-on:play="$store.player.playing = true"
           x-on:pause="$store.player.playing = false"
           x-on:ended="$store.player.playing = false"
           x-on:timeupdate="$store.player.sync()"
           x-on:loadedmetadata="$store.player.sync()"
           x-on:durationchange="$store.player.sync()"
           x-on:error="$store.player.failed($event.target)"></audio>

    <div class="flex w-72 min-w-0 items-center gap-3">
        <template x-if="$store.player.track?.cover">
            <img :src="$store.player.track.cover" class="size-11 shrink-0 rounded-md object-cover ring-1 ring-zinc-700">
        </template>
        <template x-if="! $store.player.track?.cover">
            <div class="grid size-11 shrink-0 place-items-center rounded-md bg-gradient-to-br from-blue-600/40 to-violet-600/40">
                <svg class="size-5 text-zinc-300" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3v10.55A4 4 0 1 0 11 17V7h6V3H9Z"/></svg>
            </div>
        </template>
        <div class="min-w-0">
            <div class="truncate text-sm font-medium" x-text="$store.player.track?.title" :title="$store.player.track?.title"></div>
            <div x-show="$store.player.error" x-cloak class="truncate text-xs text-red-300" x-text="$store.player.error" :title="$store.player.error"></div>
            <div x-show="! $store.player.error" class="truncate text-xs text-zinc-400" x-text="[$store.player.track?.artist, $store.player.track?.album].filter(Boolean).join(' · ')"
                 :title="[$store.player.track?.artist, $store.player.track?.album].filter(Boolean).join(' · ')"></div>
        </div>
    </div>

    <div class="flex min-w-0 flex-1 items-center gap-3">
        <button x-on:click="$store.player.seek($store.player.time - 10)" class="text-zinc-400 hover:text-white" title="10 s zurück">
            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/></svg>
        </button>
        <button x-on:click="$store.player.toggle()" class="grid size-9 place-items-center rounded-full bg-gradient-to-br from-blue-500 to-violet-600 text-white shadow" title="Play/Pause (Leertaste)">
            <svg x-show="! $store.player.playing" class="size-4 translate-x-px" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            <svg x-show="$store.player.playing" class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
        </button>
        <button x-on:click="$store.player.seek($store.player.time + 10)" class="text-zinc-400 hover:text-white" title="10 s vor">
            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 17l5-5-5-5M6 17l5-5-5-5"/></svg>
        </button>
        <span class="w-10 text-right text-xs text-zinc-400 tabular-nums" x-text="$store.player.format($store.player.dragging ? $store.player.dragTime : $store.player.time)"></span>
        {{-- beim Ziehen nicht nachführen und erst beim Loslassen springen (sonst springt der Regler zurück und jeder Pixel lädt neu) --}}
        <input type="range" min="0" step="0.1" :max="$store.player.duration || 0" aria-label="Position"
               x-effect="if (! $store.player.dragging) $el.value = $store.player.time"
               x-on:pointerdown="$store.player.dragging = true; $store.player.dragTime = Number($el.value)"
               x-on:input="$store.player.dragTime = Number($event.target.value)"
               x-on:change="$store.player.seek(Number($event.target.value)); $store.player.dragging = false"
               x-on:pointerup="$store.player.seek(Number($el.value)); $store.player.dragging = false" x-on:pointercancel="$store.player.dragging = false"
               class="h-1 min-w-0 flex-1 cursor-pointer accent-violet-500">
        <span class="w-10 text-xs text-zinc-400 tabular-nums" x-text="$store.player.format($store.player.duration)"></span>
    </div>

    {{-- Bewertung des laufenden Titels --}}
    <div x-show="$store.player.rateable" x-cloak class="flex items-center" x-data="{ hover: 0 }" x-on:mouseleave="hover = 0" title="Bewerten (derselbe Stern noch mal = entfernen)">
        <template x-for="n in 5" :key="n">
            <button type="button" x-on:click="$store.player.rate(n)" x-on:mouseenter="hover = n" class="px-px text-lg leading-none transition"
                    :class="(hover ? hover >= n : $store.player.rating >= n) ? 'text-amber-400' : 'text-zinc-600 hover:text-zinc-400'">★</button>
        </template>
    </div>

    <div class="flex items-center gap-2">
        <svg class="size-4 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5 6 9H3v6h3l5 4V5zM15.5 8.5a5 5 0 0 1 0 7"/></svg>
        <input type="range" min="0" max="1" step="0.05" aria-label="Lautstärke" :value="$store.player.volume" x-on:input="$store.player.setVolume(Number($event.target.value))" class="h-1 w-24 cursor-pointer accent-blue-500">
        <button x-on:click="$store.player.close()" class="ml-2 text-zinc-500 hover:text-white" title="Player schließen">✕</button>
    </div>
</div>
