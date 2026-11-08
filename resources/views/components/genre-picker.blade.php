{{-- Durchsuchbare Genre-Auswahl. $selected: gewählte Genres, $row: Track-Index (null = Album). --}}
@props(['options', 'selected' => [], 'row' => null, 'compact' => false, 'placeholder' => '+ wählen'])
@php
    $items = array_map(fn ($o) => ['name' => $o['name'], 'label' => $o['label'], 'sub' => (bool) $o['parent'],
        'search' => mb_strtolower($o['label'].' '.\App\Support\ArtistNames::stripAccents($o['label']))], $options);
    $rowArg = $row === null ? 'null' : (int) $row;
@endphp
<div x-data="{
        open: false, q: '', active: 0,
        items: @js($items),
        get hits() {
            const q = this.q.trim().toLowerCase();
            return q ? this.items.filter(i => q.split(/\s+/).every(w => i.search.includes(w))) : this.items;
        },
        pick(name) { $wire.toggleGenre(name, {{ $rowArg }}); this.q = ''; this.active = 0; },
        show() { this.open = true; this.$nextTick(() => this.$refs.q.focus()); },
    }" x-on:click.outside="open = false; q = ''" x-on:keydown.escape.prevent="open = false; q = ''" class="relative {{ $compact ? '' : 'inline-block' }}">
    @if ($compact)
        <button type="button" x-on:click="open ? open = false : show()" class="input-sm w-full truncate text-left text-xs {{ $selected ? 'text-zinc-200' : 'text-zinc-500' }}" title="{{ implode(', ', $selected) }}">
            {{ $selected ? implode(', ', $selected) : $placeholder }}
        </button>
    @else
        <button type="button" x-on:click="open ? open = false : show()" class="badge-gray hover:bg-zinc-600">{{ $placeholder }}</button>
    @endif
    <div x-show="open" x-cloak x-transition.opacity
         class="absolute z-30 mt-1 w-72 rounded-lg border border-zinc-700 bg-zinc-900 p-1 shadow-xl {{ $compact ? 'right-0' : 'left-0' }}">
        <input x-ref="q" x-model="q" x-on:input="active = 0" placeholder="Genre suchen …"
               x-on:keydown.down.prevent="active = Math.min(active + 1, hits.length - 1)"
               x-on:keydown.up.prevent="active = Math.max(active - 1, 0)"
               x-on:keydown.enter.prevent="hits[active] && pick(hits[active].name)"
               class="input-sm mb-1">
        <div class="max-h-64 overflow-y-auto">
            <template x-for="(i, idx) in hits" :key="i.name">
                <button type="button" x-on:click="pick(i.name)" x-on:mouseenter="active = idx"
                        :class="[i.sub && ! q ? 'pl-6 text-zinc-300' : 'font-medium', idx === active ? 'bg-zinc-800' : '', @js($selected).includes(i.name) ? 'text-blue-300' : '']"
                        class="flex w-full items-center rounded px-2 py-1 text-left text-sm">
                    <span x-text="q && i.sub ? i.label : i.name"></span>
                    <span x-show="@js($selected).includes(i.name)" class="ml-auto">✓</span>
                </button>
            </template>
            <p x-show="! hits.length" class="px-2 py-1 text-xs text-zinc-500">Kein Genre gefunden – unter Einstellungen › Genre-Liste ergänzen.</p>
        </div>
    </div>
</div>
