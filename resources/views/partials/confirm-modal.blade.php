{{-- Rückfrage-Modal für wire:confirm (siehe resources/js/app.js) – statt des Browser-Dialogs --}}
<div x-data="{ open: false, message: '', label: null, danger: false, run: null, downOutside: false,
              show(d) { this.message = d.message; this.label = d.label; this.danger = d.danger; this.run = d.run; this.open = true; this.$nextTick(() => this.$refs.ok.focus()) },
              ok() { const run = this.run; this.close(); if (run) run() },
              close() { this.open = false; this.run = null } }"
     x-on:mw-confirm.window="show($event.detail)"
     x-on:keydown.escape.window="open && close()"
     x-show="open" x-cloak
     class="fixed inset-0 z-[60] grid place-items-center bg-black/70 p-6 backdrop-blur-sm"
     x-on:mousedown.self="downOutside = true" x-on:click.self="if (downOutside) close(); downOutside = false"
     x-on:mouseup="if ($event.target !== $el) downOutside = false"
     x-transition.opacity.duration.100ms>
    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-900 shadow-2xl" role="dialog" aria-modal="true">
        <div class="flex items-start gap-3 px-6 pt-5">
            <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full" :class="danger ? 'bg-red-500/15 text-red-300' : 'bg-violet-500/15 text-violet-300'">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" x-show="danger"/><path d="M12 16v-4m0-4h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z" x-show="! danger"/></svg>
            </span>
            <p class="whitespace-pre-line pt-1 text-sm leading-relaxed text-zinc-200" x-text="message"></p>
        </div>
        <div class="mt-5 flex justify-end gap-2 border-t border-zinc-800 bg-zinc-950/40 px-6 py-3">
            <button type="button" x-on:click="close()" class="btn-ghost btn-sm">Abbrechen</button>
            <button type="button" x-ref="ok" x-on:click="ok()" x-on:keydown.enter.prevent="ok()"
                    :class="danger ? 'bg-red-600 hover:bg-red-500 text-white' : ''" class="btn-primary btn-sm" x-text="label || (danger ? 'Endgültig ausführen' : 'OK')"></button>
        </div>
    </div>
</div>
