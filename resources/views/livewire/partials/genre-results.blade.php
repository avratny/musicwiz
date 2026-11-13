{{-- Treffer der Genre-Suche (genrePicker): Klick oder Enter übernimmt sofort --}}
<div x-show="open && results.length" x-cloak
     class="absolute left-0 right-0 top-full z-30 mt-1 max-h-80 overflow-y-auto rounded-md border border-zinc-700 bg-zinc-900 py-1 shadow-xl">
    <template x-for="(r, i) in results" :key="r.path">
        <button type="button" @click="take(r)" @mouseenter="active = i"
                :class="i === active ? 'bg-zinc-800 text-zinc-100' : 'text-zinc-300'"
                class="block w-full px-3 py-1.5 text-left text-sm">
            <span class="block" x-text="r.value"></span>
            <span class="block truncate text-[11px] text-zinc-500" x-text="r.path"></span>
        </button>
    </template>
</div>
