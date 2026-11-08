@php $rel = fn ($p) => str_starts_with((string) $p, $base.'/') ? substr($p, strlen($base) + 1) : $p; @endphp
<div class="flex h-full flex-col overflow-hidden" @if ($active) wire:poll.2s @endif>
    <x-page-header title="Verlauf" subtitle="Jede Aktion wird protokolliert (alte/neue Pfade und Tags) und lässt sich rückgängig machen." />
    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        <div class="space-y-2">
            @forelse ($ops as $op)
                <div wire:key="op{{ $op->id }}" class="card">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <button wire:click="toggle({{ $op->id }})" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                            <span class="text-zinc-500">{{ $open === $op->id ? '▾' : '▸' }}</span>
                            <div class="min-w-0">
                                <div class="truncate font-medium" title="{{ $op->description }}">{{ $op->description }}</div>
                                <div class="text-xs text-zinc-500">{{ $op->created_at->format('d.m.Y H:i') }} · {{ $op->done }}/{{ $op->total }} Schritte · {{ $op->entries_count }} Einträge @if ($op->failed) · <span class="text-red-400">{{ $op->failed }} Fehler</span>@endif</div>
                            </div>
                        </button>
                        <span @class([
                            'badge-blue' => $op->isActive(),
                            'badge-green' => $op->status === 'done',
                            'badge-red' => $op->status === 'failed',
                            'badge-gray' => $op->status === 'undone',
                            'badge-amber' => $op->status === 'partly_undone',
                        ])>{{ $op->statusLabel() }}</span>
                        @if ($op->canUndo())
                            @if ($confirmUndo === $op->id)
                                <button wire:click="undo({{ $op->id }})" class="btn-danger btn-sm">Wirklich rückgängig?</button>
                                <button wire:click="$set('confirmUndo', null)" class="btn-ghost btn-sm">Nein</button>
                            @else
                                <button wire:click="$set('confirmUndo', {{ $op->id }})" class="btn-ghost btn-sm">Rückgängig</button>
                            @endif
                        @endif
                    </div>
                    @if ($op->error)
                        <div class="border-t border-zinc-800 px-4 py-2 text-xs whitespace-pre-line text-red-300">{{ $op->error }}</div>
                    @endif
                    @if ($open === $op->id)
                        <div class="max-h-96 overflow-y-auto border-t border-zinc-800 px-4 py-2 font-mono text-xs">
                            @foreach ($entries as $e)
                                <div class="border-b border-zinc-800/60 py-1.5">
                                    <span class="mr-2 inline-block w-14 text-violet-300">{{ $e->kind }}</span>
                                    @if ($e->kind === 'tags')
                                        <span class="text-zinc-400">{{ $rel($e->old_path) }}</span>
                                        <div class="ml-16 text-zinc-500">
                                            @foreach ($e->new_tags ?? [] as $k => $v)
                                                {{ strtoupper($k) }}: <span class="line-through">{{ is_array($e->old_tags[$k] ?? null) ? implode(' ; ', $e->old_tags[$k]) : json_encode($e->old_tags[$k] ?? '', JSON_UNESCAPED_UNICODE) }}</span> → <span class="text-zinc-200">{{ is_array($v) ? implode(' ; ', $v) : json_encode($v, JSON_UNESCAPED_UNICODE) }}</span>&nbsp;&nbsp;
                                            @endforeach
                                            @if ($e->cover_changed) Cover geändert @endif
                                        </div>
                                    @elseif ($e->kind === 'error')
                                        <span class="text-red-300">{{ $e->error }}</span>
                                    @elseif ($e->kind === 'info')
                                        <span class="text-zinc-400">{{ $rel($e->old_path) }}</span> <span class="text-zinc-500">– {{ $e->error }}</span>
                                    @else
                                        <span class="text-zinc-400">{{ $rel($e->old_path) }}</span>
                                        @if ($e->new_path)<span class="text-blue-300"> → {{ $rel($e->new_path) }}</span>@endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="py-10 text-center text-zinc-500">Noch keine Aktionen.</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $ops->links() }}</div>
    </div>
</div>
