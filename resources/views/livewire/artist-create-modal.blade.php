<div>
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" wire:keydown.escape.window="close"
             x-data x-init="$nextTick(() => $refs.name.select())">
            <div class="absolute inset-0" wire:click="close"></div>
            <form wire:submit="save" class="relative w-full max-w-md rounded-xl border border-zinc-700 bg-zinc-900 p-6 shadow-2xl">
                <h2 class="text-lg font-semibold">Interpret anlegen</h2>
                <p class="mt-1 text-sm text-zinc-500">Mit der richtigen Schreibweise – so landet er im Verzeichnis.</p>
                <label class="mt-5 block">
                    <span class="mb-1 block text-xs font-medium text-zinc-500">Name</span>
                    <input type="text" wire:model="name" x-ref="name" class="field" autocomplete="off">
                </label>
                <label class="mt-3 flex items-center gap-2 text-sm text-zinc-300">
                    <input type="checkbox" wire:model="confirmed" class="size-4 rounded border-zinc-600 accent-[var(--color-accent)]">
                    Schreibweise bestätigt
                </label>
                @if ($error)
                    <div class="mt-4 rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
                @endif
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="btn" wire:click="close">Abbrechen</button>
                    <button type="submit" class="btn-primary">Anlegen</button>
                </div>
            </form>
        </div>
    @endif
</div>
