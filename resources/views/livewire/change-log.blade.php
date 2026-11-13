@php
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
    $labels = \App\Models\Track::EDITABLE;
    $th = function (string $key, string $label) use ($sort, $dir) {
        $arrow = $sort === $key ? '<span class="text-accent">'.($dir === 'asc' ? '↑' : '↓').'</span>' : '';

        return '<button class="flex items-center gap-1 uppercase hover:text-zinc-100" wire:click="sortBy(\''.$key.'\')">'.e($label).' '.$arrow.'</button>';
    };
    $value = fn ($field, $v) => $v === null || $v === '' ? '∅' : ($field === 'compilation' ? ($v === '1' ? 'ja' : 'nein') : $v);
    $fieldLabel = fn ($f) => $f === 'track_total' ? 'Titel gesamt' : ($f === 'disc_total' ? 'CDs gesamt' : ($labels[$f] ?? $f));
    $badge = fn ($source) => '<span class="rounded px-1.5 py-0.5 text-xs font-medium '.($source === 'manual' ? 'bg-blue-500/15 text-blue-300' : 'bg-amber-500/15 text-amber-300').'">'.($source === 'manual' ? 'manuell' : 'System').'</span>';
@endphp
<div>
    <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center gap-3">
            <div class="mr-2">
                <h1 class="text-lg font-semibold leading-tight">Änderungen</h1>
                <p class="text-xs text-zinc-500">{{ $fmt($total) }} {{ $total === 1 ? 'Änderung' : 'Änderungen' }} · nur in der Datenbank, noch nicht in den Dateien</p>
            </div>
            <div class="relative min-w-60 flex-1">
                <svg class="pointer-events-none absolute left-2.5 top-2 size-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="q" placeholder="Suchen: Lied, alter oder neuer Wert, Grund …" class="field pl-8">
            </div>
            <select class="field w-auto" wire:model.live="field" title="Feld">
                <option value="">Alle Felder</option>
                @foreach ($labels as $key => $label)
                    <option value="{{ $key }}">{{ $label === 'von' ? ($key === 'track_total' ? 'Titel gesamt' : 'CDs gesamt') : $label }}</option>
                @endforeach
            </select>
            <select class="field w-auto" wire:model.live="source" title="Quelle">
                <option value="">Manuell und System</option>
                <option value="manual">Manuell</option>
                <option value="system">System</option>
            </select>
            <select class="field w-auto" wire:model.live="location" title="Bereich">
                <option value="">Alle Bereiche</option>
                @foreach (\App\Support\Locations::LABELS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <label class="flex cursor-pointer select-none items-center gap-2 text-sm text-zinc-300">
                <input type="checkbox" wire:model.live="grouped" class="size-4 rounded border-zinc-600 accent-[var(--color-accent)]">
                Nach Album gruppiert
            </label>
            @if ($filtered)
                <button class="text-xs text-accent hover:underline" wire:click="resetFilters">Filter zurücksetzen</button>
            @endif
            @if ($total)
                <button class="btn ml-auto" wire:click="revertAll"
                        data-confirm-title="Änderungen zurücksetzen" data-confirm-ok="Zurücksetzen" data-confirm-danger data-confirm="{{ $filtered ? 'Die '.$fmt($total).' gefilterten Änderungen' : 'Alle '.$fmt($total).' Änderungen' }} zurücksetzen? Die Lieder bekommen wieder den Stand der Datei.">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M3 7v6h6"/><path d="M3 13a9 9 0 1 0 3-7.7L3 8"/></svg>
                    {{ $filtered ? 'Gefilterte zurücksetzen' : 'Alle zurücksetzen' }}
                </button>
            @endif
        </div>
    </header>

    @if ($error)
        <div class="mx-6 mt-4 rounded-md bg-red-500/15 px-3 py-2 text-sm text-red-300">{{ $error }}</div>
    @elseif ($message)
        <div class="mx-6 mt-4 rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{{ $message }}</div>
    @endif

    @if ($grouped)
        {{-- gruppiert: Album → Lied → Änderungen --}}
        <table class="w-full table-fixed border-separate border-spacing-0 text-sm">
            <colgroup><col class="w-40"><col class="w-36"><col><col class="w-24"><col class="w-[24%]"></colgroup>
            <thead class="sticky top-[61px] z-10 bg-zinc-950 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="border-b border-zinc-800 py-2 pl-6 pr-2">{!! $th('created_at', 'Zeit') !!}</th>
                    <th class="border-b border-zinc-800 px-2 py-2">Feld</th>
                    <th class="border-b border-zinc-800 px-2 py-2">Alt → Neu</th>
                    <th class="border-b border-zinc-800 px-2 py-2">Quelle</th>
                    <th class="border-b border-zinc-800 px-2 py-2 pr-6">Grund</th>
                </tr>
            </thead>
            @forelse ($groups as $g)
                <tbody wire:key="g-{{ $g['hash'] }}">
                    <tr>
                        <td colspan="5" class="border-b border-zinc-800 bg-zinc-900 px-6 pb-2 pt-5">
                            <div class="flex items-center gap-4">
                                <img src="{{ \App\Support\Covers::url($g['cover'], 300) }}" loading="lazy" alt="" class="cover-link size-12 shrink-0 rounded-md bg-zinc-800 object-cover" onerror="noCover(this)"
                                     @click="$dispatch('open-cover-picker', { album: @js($g['hash']) })" title="Cover ändern">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-semibold">{{ $g['album'] ?: 'Ohne Album' }}</div>
                                    <div class="truncate text-xs text-zinc-500">
                                        {{ collect([$g['albumartist'], \App\Support\Locations::LABELS[$g['location']] ?? null, $g['album'] ? null : basename((string) $g['dir'])])->filter()->implode(' · ') }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right text-xs text-zinc-500">
                                    {{ $fmt($g['changes']) }} {{ $g['changes'] === 1 ? 'Änderung' : 'Änderungen' }}<br>
                                    zuletzt {{ \Illuminate\Support\Carbon::parse($g['last'])->format('d.m.Y H:i') }}
                                </div>
                            </div>
                        </td>
                    </tr>
                    @foreach ($g['tracks'] as $trackId => $changes)
                        @php $t = $changes->first(); @endphp
                        <tr wire:key="t-{{ $trackId }}">
                            <td colspan="5" class="border-b border-zinc-800/70 px-6 pb-1 pt-3">
                                <a href="{{ route('location', ['slug' => \App\Support\Locations::SLUGS[$t->location], 'q' => $t->filename]) }}" wire:navigate
                                   class="group inline-flex max-w-full items-baseline gap-2 hover:text-accent" title="{{ $t->filename }}">
                                    @if ($t->track)<span class="tabular-nums text-xs text-zinc-500">{{ $t->disc > 1 ? $t->disc.'-' : '' }}{{ $t->track }}</span>@endif
                                    <span class="truncate font-medium">{{ $t->title ?: $t->filename }}</span>
                                    <span class="truncate text-xs text-zinc-500 group-hover:text-accent">{{ $t->artist }}</span>
                                </a>
                            </td>
                        </tr>
                        @foreach ($changes as $c)
                            <tr wire:key="c-{{ $c->id }}" class="group/row align-top hover:bg-zinc-900">
                                <td class="border-b border-zinc-800/40 py-1.5 pl-10 pr-2 tabular-nums text-xs text-zinc-500">{{ $c->created_at?->format('d.m.Y H:i:s') }}</td>
                                <td class="border-b border-zinc-800/40 px-2 py-1.5 text-zinc-300">{{ $fieldLabel($c->field) }}</td>
                                <td class="border-b border-zinc-800/40 px-2 py-1.5">
                                    @if ($c->field === 'cover')
                                        @include('livewire.partials.cover-change', ['c' => $c])
                                    @else
                                        <span class="break-words text-zinc-500 line-through decoration-zinc-600">{{ $value($c->field, $c->old_value) }}</span>
                                        <span class="text-zinc-600">→</span>
                                        <span class="break-words text-zinc-100">{{ $value($c->field, $c->new_value) }}</span>
                                    @endif
                                </td>
                                <td class="border-b border-zinc-800/40 px-2 py-1.5">{!! $badge($c->source) !!}</td>
                                <td class="border-b border-zinc-800/40 px-2 py-1.5 pr-6 text-xs text-zinc-400"><button class="float-right ml-2 text-xs font-medium text-accent opacity-0 hover:underline group-hover/row:opacity-100 focus:opacity-100"
                                            wire:click="revert({{ $c->track_id }}, '{{ $c->field }}')"
                                            title="{{ $fieldLabel($c->field) }} auf den Stand der Datei zurücksetzen (alle Änderungen dieses Felds bei diesem Lied)">Zurücksetzen</button>{{ $c->reason }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="5" class="px-6 py-16 text-center text-zinc-500">{{ $filtered ? 'Keine passenden Änderungen.' : 'Noch keine Änderungen.' }}</td></tr></tbody>
            @endforelse
        </table>
    @else
    <table class="w-full table-fixed border-separate border-spacing-0 text-sm">
        <colgroup><col class="w-36"><col class="w-[22%]"><col class="w-24"><col class="w-32"><col><col class="w-24"><col class="w-[18%]"></colgroup>
        <thead class="sticky top-[61px] z-10 bg-zinc-950 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
            <tr>
                <th class="border-b border-zinc-800 py-2 pl-6 pr-2">{!! $th('created_at', 'Zeit') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('title', 'Lied') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('location', 'Bereich') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('field', 'Feld') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2">Alt → Neu</th>
                <th class="border-b border-zinc-800 px-2 py-2">{!! $th('source', 'Quelle') !!}</th>
                <th class="border-b border-zinc-800 px-2 py-2 pr-6">{!! $th('reason', 'Grund') !!}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $c)
                <tr wire:key="c-{{ $c->id }}" class="group/row align-top hover:bg-zinc-900">
                    <td class="border-b border-zinc-800/70 py-2 pl-6 pr-2 tabular-nums text-xs text-zinc-500">{{ $c->created_at?->format('d.m.Y H:i:s') }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2">
                        <a href="{{ route('location', ['slug' => \App\Support\Locations::SLUGS[$c->location], 'q' => $c->filename]) }}" wire:navigate
                           class="block truncate hover:text-accent" title="{{ $c->filename }}">{{ $c->title ?: $c->filename }}</a>
                        <div class="truncate text-xs text-zinc-500">{{ $c->artist }}</div>
                    </td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-xs text-zinc-400">{{ \App\Support\Locations::LABELS[$c->location] ?? $c->location }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 text-zinc-300">{{ $fieldLabel($c->field) }}</td>
                    <td class="border-b border-zinc-800/70 px-2 py-2">
                        @if ($c->field === 'cover')
                            @include('livewire.partials.cover-change', ['c' => $c])
                        @else
                            <span class="break-words text-zinc-500 line-through decoration-zinc-600">{{ $value($c->field, $c->old_value) }}</span>
                            <span class="text-zinc-600">→</span>
                            <span class="break-words text-zinc-100">{{ $value($c->field, $c->new_value) }}</span>
                        @endif
                    </td>
                    <td class="border-b border-zinc-800/70 px-2 py-2">
                        {!! $badge($c->source) !!}
                    </td>
                    <td class="border-b border-zinc-800/70 px-2 py-2 pr-6 text-xs text-zinc-400"><button class="float-right ml-2 text-xs font-medium text-accent opacity-0 hover:underline group-hover/row:opacity-100 focus:opacity-100"
                                            wire:click="revert({{ $c->track_id }}, '{{ $c->field }}')"
                                            title="{{ $fieldLabel($c->field) }} auf den Stand der Datei zurücksetzen (alle Änderungen dieses Felds bei diesem Lied)">Zurücksetzen</button>{{ $c->reason }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-16 text-center text-zinc-500">{{ $filtered ? 'Keine passenden Änderungen.' : 'Noch keine Änderungen.' }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @endif

    @if ($more)
        <div class="py-6 text-center" x-intersect.margin.600px="$wire.loadMore()">
            <button class="btn" wire:click="loadMore">Mehr laden</button>
        </div>
    @endif
</div>
