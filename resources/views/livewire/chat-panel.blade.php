{{-- Assistent: runder Knopf unten rechts, Chat als Overlay am rechten Rand (bleibt beim Seitenwechsel offen) --}}
<div x-data="{ open: localStorage.getItem('chatOpen') === '1', toggle() { this.open = ! this.open; localStorage.setItem('chatOpen', this.open ? '1' : '0'); if (this.open) this.$nextTick(() => this.$refs.input?.focus()) } }"
     x-on:keydown.escape.window="if (open) toggle()" @if (in_array($status, ['running'], true) && ! $stale) wire:poll.1500ms @endif>
    {{-- Floating Button --}}
    <button x-on:click="toggle()" x-show="! open" class="fixed right-5 bottom-24 z-40 grid size-14 place-items-center rounded-full bg-gradient-to-br from-blue-500 to-violet-600 text-white shadow-xl ring-1 ring-white/10 transition hover:scale-105"
            title="Assistent öffnen">
        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.4-4 8-9 8a9.9 9.9 0 0 1-4.3-1L3 20l1.3-3.9A7.6 7.6 0 0 1 3 12c0-4.4 4-8 9-8s9 3.6 9 8Z"/></svg>
        @if ($status === 'running')<span class="absolute top-1 right-1 size-3 animate-pulse rounded-full bg-amber-300"></span>@elseif ($status === 'awaiting')<span class="absolute top-1 right-1 size-3 rounded-full bg-amber-400"></span>@endif
    </button>

    {{-- Overlay --}}
    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-y-0 right-0 z-40 flex w-full max-w-md flex-col border-l border-zinc-800 bg-zinc-950/95 shadow-2xl backdrop-blur">
        <div class="flex items-center gap-2 border-b border-zinc-800 px-4 py-3">
            <div class="min-w-0 flex-1">
                <div class="font-semibold text-zinc-100">Assistent</div>
                <div class="truncate text-[11px] text-zinc-500">{{ $modelLabel }}@if ($conversation) · Unterhaltung ca. {{ \App\Livewire\ChatPanel::money($cost) }}@endif</div>
            </div>
            @if ($conversation)<button wire:click="newConversation" class="btn-ghost btn-sm" title="Neue Unterhaltung" @disabled(in_array($status, ['running', 'awaiting'], true))>Neu</button>@endif
            <button x-on:click="toggle()" class="text-zinc-400 hover:text-white" title="Schließen (Esc)">✕</button>
        </div>

        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3 text-sm" x-ref="log" x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true })">
            @if (! $available)
                <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-amber-200">Für den Assistenten fehlt der Anthropic-API-Key – in den <a href="{{ route('settings', ['tab' => 'access']) }}" wire:navigate class="underline">Einstellungen › Zugangsdaten</a> eintragen.</div>
            @elseif (! $items)
                <div class="text-zinc-400">
                    <p>Frag mich etwas zu deiner Sammlung, z. B.:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-zinc-500">
                        <li>Was ist auf dieser Seite noch zu tun?</li>
                        <li>Welche Kombi-Einträge sind eigentlich Zusammenarbeiten?</li>
                        <li>Schau dir an, was vorgemerkt ist – passt das?</li>
                        <li>Warum ist das letzte Schreiben fehlgeschlagen?</li>
                    </ul>
                    <p class="mt-3 text-xs text-zinc-600">Ich lese über die Werkzeuge der App; Änderungen lege ich dir zur Freigabe vor. In die Dateien schreibe ich nie.</p>
                </div>
            @endif

            @foreach ($items as $i => $item)
                <div wire:key="ci{{ $i }}">
                    @switch($item['type'])
                        @case('user')
                            <div class="ml-8 rounded-2xl rounded-br-sm bg-blue-600/25 px-3 py-2 whitespace-pre-line text-zinc-100">{{ $item['text'] }}</div>
                            @break
                        @case('assistant')
                            <div class="chat-md text-zinc-200">{!! \Illuminate\Support\Str::markdown($item['text'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                            @if (isset($item['cost']))<div class="mt-0.5 text-[10px] text-zinc-600">ca. {{ \App\Livewire\ChatPanel::money($item['cost']) }}</div>@endif
                            @break
                        @case('tool')
                            <div class="flex items-center gap-1.5 text-xs text-zinc-500"><span class="size-1.5 rounded-full bg-zinc-600"></span>{{ $item['label'] }}{{ isset($item['input']['key']) ? ' („'.$item['input']['key'].'“)' : '' }}{{ isset($item['input']['q']) && $item['input']['q'] !== '' ? ' „'.$item['input']['q'].'“' : '' }}</div>
                            @break
                        @case('proposal')
                            <div class="rounded-xl border {{ ['open' => 'border-amber-500/40 bg-amber-500/5', 'done' => 'border-emerald-500/30 bg-emerald-500/5', 'rejected' => 'border-zinc-700', 'failed' => 'border-red-500/40 bg-red-500/5'][$item['state']] ?? 'border-zinc-700' }} p-3" x-data="{ details: false }">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-zinc-100">{{ $item['label'] }}</span>
                                    <span class="ml-auto text-[11px] {{ ['open' => 'text-amber-300', 'done' => 'text-emerald-300', 'rejected' => 'text-zinc-500', 'failed' => 'text-red-300'][$item['state']] ?? '' }}">{{ ['open' => 'wartet auf Freigabe', 'done' => 'ausgeführt', 'rejected' => 'abgelehnt', 'failed' => 'fehlgeschlagen'][$item['state']] ?? '' }}</span>
                                </div>
                                <div class="mt-1 text-xs text-zinc-400">{{ \App\Support\Chat\ChatPanelText::summary($item['name'], $item['input']) }}</div>
                                <button x-on:click="details = ! details" class="mt-1 text-[11px] text-zinc-500 hover:text-zinc-300" x-text="details ? 'Details ausblenden' : 'Details'"></button>
                                <pre x-show="details" x-cloak class="mt-1 max-h-48 overflow-auto rounded bg-zinc-900 p-2 text-[11px] text-zinc-400">{{ json_encode($item['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                @if (! empty($item['result']))<div class="mt-1 text-xs text-red-300">{{ $item['result'] }}</div>@endif
                                @if ($item['state'] === 'open')
                                    <div class="mt-2 flex gap-2">
                                        <button wire:click="decide(@js($item['id']), true)" wire:loading.attr="disabled" class="btn-primary btn-sm">Ausführen</button>
                                        <button wire:click="decide(@js($item['id']), false)" wire:loading.attr="disabled" class="btn-ghost btn-sm">Ablehnen</button>
                                    </div>
                                @endif
                            </div>
                            @break
                        @case('error')
                            <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-200">{{ $item['text'] }}</div>
                            @break
                    @endswitch
                </div>
            @endforeach

            @if ($status === 'running')
                <div class="flex items-center gap-2 text-xs text-violet-300"><span class="size-2 animate-pulse rounded-full bg-violet-400"></span>{{ $stale ? 'hängt seit über 10 Minuten' : 'arbeitet …' }}
                    @if ($stale)<button wire:click="reset_" class="underline">freigeben</button>@endif
                </div>
            @elseif ($status === 'awaiting')
                <div class="text-xs text-amber-300">Wartet auf deine Freigabe (oben).</div>
            @endif
        </div>

        <form class="border-t border-zinc-800 p-3" x-on:submit.prevent="$wire.send(document.title + ' – ' + location.pathname + location.search)">
            <textarea x-ref="input" wire:model="text" rows="2" placeholder="{{ $available ? 'Nachricht … (Enter senden, Umschalt+Enter neue Zeile)' : 'Erst den API-Key eintragen' }}"
                      x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                      class="input w-full resize-none" @disabled(! $available || in_array($status, ['running', 'awaiting'], true))></textarea>
        </form>
    </div>
</div>
