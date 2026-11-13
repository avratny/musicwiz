{{-- Dialog „Neues Genre“ (Genres › Nicht zugeordnet, Sonstiges › Genre): Name, wo drunter (Suche, nur Hauptgenres und
     Familien – leer = neues Hauptgenre). Geöffnet per $dispatch('new-genre', { name, ctx }); $submit ist der Aufruf
     (mit ctx, name, parent), $hint der Untertitel (JS-Ausdruck mit ctx). --}}
<div x-data="{ show: false, ctx: {}, name: '', parent: null, parentPath: '' }" x-cloak x-show="show"
     @new-genre.window="show = true; ctx = $event.detail.ctx ?? {}; name = $event.detail.name ?? ''; parent = null; parentPath = ''; $nextTick(() => $refs.name.select())"
     @keydown.escape.window="show = false"
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
    <form @submit.prevent="if (name.trim()) { {{ $submit }}; show = false }" @click.outside="show = false"
          class="w-full max-w-lg space-y-4 rounded-lg border border-zinc-700 bg-zinc-900 p-5 shadow-2xl">
        <div>
            <h2 class="text-base font-semibold">Neues Genre</h2>
            <p class="text-xs text-zinc-500" x-text="{{ $hint }}"></p>
        </div>
        <label class="block space-y-1">
            <span class="text-xs text-zinc-400">Name</span>
            <input type="text" x-ref="name" x-model="name" class="field w-full">
        </label>
        <div class="space-y-1">
            <span class="text-xs text-zinc-400">Wo drunter?</span>
            <div x-data="genrePicker(null, null, { maxLevel: 2, take: (r) => { parent = r.id; parentPath = r.path } })" @new-genre.window="q = ''"
                 @keydown="keys($event)" @click.outside="open = false" class="relative">
                <input type="text" x-ref="input" x-model="q" @focus="open = true" @input="open = true; active = 0; place(); parent = null; parentPath = ''"
                       placeholder="Hauptgenre oder Familie suchen – leer lassen für ein neues Hauptgenre" autocomplete="off" class="field w-full">
                @include('livewire.partials.genre-results')
            </div>
            <p class="text-xs" :class="parent ? 'text-emerald-300' : 'text-zinc-500'"
               x-text="parent ? parentPath + ' › ' + (name.trim() || '…') : 'Neues Hauptgenre: ' + (name.trim() || '…')"></p>
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn" @click="show = false">Abbrechen</button>
            <button type="submit" class="btn-primary" :disabled="!name.trim()">Anlegen und übernehmen</button>
        </div>
    </form>
</div>
