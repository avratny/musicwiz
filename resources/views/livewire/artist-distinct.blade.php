{{-- Interpreten › Verschieden: als „nicht derselbe“ markierte Paare – aufheben, damit sie wieder als ähnlich vorgeschlagen werden --}}
@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
@endphp
<div>
<header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
    <div class="flex flex-wrap items-center gap-4">
        <div class="mr-2">
            <h1 class="text-lg font-semibold leading-tight">Verschieden</h1>
            <p class="text-xs text-zinc-500">{{ $fmt($all) }} {{ $all === 1 ? 'Paar' : 'Paare' }} – werden nicht als ähnlich vorgeschlagen</p>
        </div>
        <div class="relative min-w-60 flex-1">
            <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.250ms="query" placeholder="Interpret suchen …" class="field pl-8">
        </div>
    </div>
</header>

<div class="max-w-4xl px-6 py-5">
    @if ($message)
        <div class="mb-4 rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    @php $count = fn ($id) => (int) ($stats[$id]->tracks ?? 0); @endphp
    <table class="w-full text-sm">
        <tbody>
            @forelse ($pairs as $p)
                <tr wire:key="pair-{{ $p->a_id }}-{{ $p->b_id }}" class="hover:bg-zinc-900">
                    <td class="border-b border-zinc-800/70 py-2 pr-2">
                        <span class="font-medium">{{ $p->a_name }}</span>
                        <span class="text-xs text-zinc-500">({{ $fmt($count($p->a_id)) }})</span>
                    </td>
                    <td class="w-8 border-b border-zinc-800/70 py-2 text-center text-zinc-500">≠</td>
                    <td class="border-b border-zinc-800/70 py-2 pr-2">
                        <span class="font-medium">{{ $p->b_name }}</span>
                        <span class="text-xs text-zinc-500">({{ $fmt($count($p->b_id)) }})</span>
                    </td>
                    <td class="w-28 border-b border-zinc-800/70 py-2 text-right text-xs tabular-nums text-zinc-500">{{ $p->created_at ? \Illuminate\Support\Carbon::parse($p->created_at)->format('d.m.Y') : '' }}</td>
                    <td class="w-28 border-b border-zinc-800/70 py-2 text-right">
                        <button class="text-xs font-medium text-accent hover:underline" wire:click="undistinct({{ $p->a_id }}, {{ $p->b_id }})"
                                title="Darf wieder als ähnlich vorgeschlagen werden">Aufheben</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-16 text-center text-zinc-500">{{ $query ? 'Nichts gefunden.' : 'Noch nichts als verschieden markiert.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="mt-2 text-xs text-zinc-500">In Klammern: Zahl der Lieder.</p>

    @if ($total > $pairs->count())
        <div class="py-4 text-center"><button class="btn" wire:click="more">Mehr laden</button></div>
    @endif
</div>
</div>
