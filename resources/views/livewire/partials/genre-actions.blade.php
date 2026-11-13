{{-- Aktionen eines Genres: Untergenre hinzufügen (Gruppen) und in eine andere Gruppe verschieben --}}
<div class="flex shrink-0 items-center gap-1" x-data="{ adding: false, name: '', moving: false, q: '' }" @click.outside="moving = false" @keydown.escape="adding = false; moving = false">
    @if ($node->level < 3)
        <template x-if="adding">
            <form class="flex gap-1" @submit.prevent="name.trim() && $wire.addChildTo({{ $node->id }}, name); name = ''; adding = false">
                <input type="text" x-model="name" x-init="$nextTick(() => $el.focus())" placeholder="{{ $node->level === 1 ? 'Neue Familie' : 'Neuer Style' }}" class="field w-44 py-1 text-xs">
                <button class="btn !py-1 text-xs">OK</button>
            </form>
        </template>
        <button x-show="!adding" class="rounded px-2 py-1 text-xs font-medium text-accent hover:bg-zinc-800" @click="adding = true; moving = false"
                title="{{ $node->level === 1 ? 'Familie' : 'Style' }} in „{{ $node->name }}“ anlegen">+ {{ $node->level === 1 ? 'Familie' : 'Style' }}</button>
    @endif
    <div class="relative">
        <button class="rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200" @click="moving = !moving; adding = false" title="In eine andere Gruppe oder Ebene verschieben">Verschieben</button>
        {{-- erst beim Öffnen aufbauen: sonst stünden die Ziele für jedes Genre im DOM (Hunderttausende Elemente, träge Seite) --}}
        <template x-if="moving">
        <div class="absolute right-0 z-30 mt-1 w-80 rounded-lg border border-zinc-700 bg-zinc-900 p-2 shadow-xl">
            <input type="search" x-model="q" x-init="$nextTick(() => $el.focus())" placeholder="Ziel suchen …" class="field mb-1 py-1 text-xs">
            <div class="max-h-72 overflow-y-auto">
                @if ($node->level > 1)
                    <button class="block w-full rounded px-2 py-1 text-left text-xs text-zinc-300 hover:bg-zinc-800" @click="$wire.move({{ $node->id }}, null); moving = false">
                        <span class="text-zinc-500">als</span> Hauptgenre</button>
                @endif
                <template x-for="t in targets.filter(t => t.id !== {{ $node->id }} && t.id !== {{ $node->parent_id ?? 0 }} && t.path.toLowerCase().includes(q.toLowerCase()))" :key="t.id">
                    <button class="block w-full truncate rounded px-2 py-1 text-left text-xs text-zinc-300 hover:bg-zinc-800" @click="$wire.move({{ $node->id }}, t.id); moving = false">
                        <span class="text-zinc-500" x-text="t.level === 1 ? 'Familie in' : 'Style in'"></span> <span x-text="t.path"></span>
                    </button>
                </template>
            </div>
        </div>
        </template>
    </div>
</div>
