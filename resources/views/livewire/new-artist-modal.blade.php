<div>
    @if ($open && $wizName !== null)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/70 p-6 backdrop-blur-sm" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="flex h-full max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl">
                <div class="flex items-center border-b border-zinc-800 px-6 py-3">
                    <h2 class="text-lg font-semibold">Neuen Künstler erfassen</h2>
                    <span class="ml-3 text-xs text-zinc-500">Speichert ins Künstlerverzeichnis – die Dateien bleiben unverändert.</span>
                    <button wire:click="close" class="ml-auto text-zinc-400 hover:text-white" title="Schließen (Esc)">✕</button>
                </div>
                @include('livewire.partials.new-artist-wizard')
            </div>
        </div>
    @endif
</div>
