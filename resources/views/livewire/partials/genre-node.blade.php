{{-- Genre als aufklappbare Gruppe (Hauptgenre, Familie) bzw. Zeile (Style) – wie die Alben --}}
@php
    $children = $byParent[$node->id] ?? collect();
    $grandchildren = $children->sum(fn ($c) => ($byParent[$c->id] ?? collect())->count());
    $count = $artistCounts[$node->id] ?? 0;
@endphp
@if ($visible === null || isset($visible[$node->id]))
    <div wire:key="genre-{{ $node->id }}">
        <div @class([
                'group flex items-center gap-3 pr-4',
                'border-b border-zinc-800 bg-zinc-900 py-3 pl-4' => $node->level === 1,
                'border-b border-zinc-800/70 bg-zinc-900/40 py-2 pl-10' => $node->level === 2,
                'border-b border-zinc-800/40 py-1.5 pl-16' => $node->level === 3,
                '!bg-accent/15' => $selected === $node->id,
             ])>
            @if ($node->level < 3)
                <button class="rounded p-0.5 text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" @click="toggle({{ $node->id }})" title="Auf-/zuklappen">
                    <svg class="size-4 transition-transform" :class="isOpen({{ $node->id }}) && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
            @endif
            <button class="flex min-w-0 flex-1 items-baseline gap-3 text-left" wire:click="open({{ $node->id }})" title="Bearbeiten">
                <span @class(['truncate', 'text-base font-semibold text-zinc-100' => $node->level === 1, 'font-medium text-zinc-200' => $node->level === 2, 'text-zinc-300' => $node->level === 3])>{{ $node->name }}</span>
                <span class="shrink-0 text-xs text-zinc-500">
                    @if ($node->level === 1){{ $children->count() }} {{ $children->count() === 1 ? 'Familie' : 'Familien' }} · {{ $grandchildren }} Styles
                    @elseif ($node->level === 2){{ $children->count() }} {{ $children->count() === 1 ? 'Style' : 'Styles' }}@endif
                    @if ($node->synonyms_count) · {{ $node->synonyms_count }} Synonym{{ $node->synonyms_count === 1 ? '' : 'e' }}@endif
                </span>
            </button>
            @if ($count)
                <span class="shrink-0 rounded-full bg-zinc-800 px-2 text-[11px] tabular-nums text-zinc-400" title="verknüpfte Interpreten">{{ $count }} Interpret{{ $count === 1 ? '' : 'en' }}</span>
            @endif
            @include('livewire.partials.genre-actions', ['node' => $node])
        </div>
        @if ($node->level < 3)
            <div x-show="isOpen({{ $node->id }})" x-cloak>
                @foreach ($children as $child)
                    @include('livewire.partials.genre-node', ['node' => $child])
                @endforeach
                @if ($children->isEmpty())
                    <div @class(['py-2 text-xs text-zinc-500', 'pl-14' => $node->level === 1, 'pl-20' => $node->level === 2])>Noch keine {{ $node->level === 1 ? 'Familien' : 'Styles' }} – oben „+ {{ $node->level === 1 ? 'Familie' : 'Style' }}“.</div>
                @endif
            </div>
        @endif
    </div>
@endif
