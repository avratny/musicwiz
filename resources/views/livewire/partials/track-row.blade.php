{{-- Eine Zeile: Klick → auswählen + Flyout (⌘/Strg, Shift: mehrere), Doppelklick auf eine Zelle → direkt bearbeiten --}}
<tr data-id="{{ $t->id }}" wire:key="t-{{ $t->id }}"
    class="group cursor-pointer"
    :class="{ 'bg-accent/15': isSel({{ $t->id }}), 'hover:bg-accent/5': !isSel({{ $t->id }}), 'text-accent font-medium': $store.player.isCurrent({{ $t->id }}) }">
    <td class="border-b border-zinc-800/70 px-3 py-1.5">
        <div class="flex items-center gap-2">
            <input type="checkbox" class="size-3.5 rounded border-zinc-600 accent-[var(--color-accent)] opacity-0 group-hover:opacity-100"
                   :class="sel.length && '!opacity-100'" :checked="isSel({{ $t->id }})" @click.stop="toggle({{ $t->id }})" title="Auswählen">
            @if ($showCover)
                <img src="{{ \App\Support\Covers::url($t, 64) }}" loading="lazy" @class(['size-8 rounded bg-zinc-800 object-cover', 'cover-link' => $canCover]) alt=""
                     @if ($canCover) onerror="noCover(this)" @click.stop="$dispatch('open-cover-picker', { track: {{ $t->id }} })" title="Cover ändern" @else onerror="this.style.visibility='hidden'" @endif>
            @endif
            <button class="text-zinc-500 opacity-0 hover:text-accent group-hover:opacity-100" @click.stop="playFrom($el, {{ $index }})" title="Abspielen">
                <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
            </button>
        </div>
    </td>
    @php
        // Kategorie je Lied: als eigene Spalte (wenn eingeblendet) oder bei Einzeltiteln rechts im Titel
        $canPick = $this->hasCategories();
    @endphp
    @foreach ($columns as $key => $c)
        @php
            $value = $t->{$c['db']};
            $muted = ! in_array($key, ['title'], true);
        @endphp
        <td @class(['truncate border-b border-zinc-800/70 px-2 py-1.5', 'text-zinc-400' => $muted && ! in_array($key, ['track', 'disc', 'duration', 'format', 'created_at'], true),
                    'tabular-nums text-zinc-500' => in_array($key, ['track', 'disc', 'duration', 'created_at'], true), 'text-xs text-zinc-500' => in_array($key, ['format', 'filename'], true)])
            @if ($c['edit']) data-field="{{ $key }}" data-value="{{ $value }}" @endif
            @if (in_array($key, ['title', 'artist', 'artists', 'album', 'albumartist', 'albumartists', 'genre', 'comment', 'filename'], true)) title="{{ $value }}" @endif>
            @switch($key)
                @case('track'){{ ! $grouped && ! isset($columns['disc']) && ($t->disc_total > 1 || $t->disc > 1) ? $t->disc.'-' : '' }}{{ $t->track }}@break
                @case('title')
                    <div class="flex items-center gap-2">
                        {{-- Einzeltitel: jedes Lied mit eigenem Cover (Klick: Cover nur für dieses Lied) --}}
                        @if ($rowCover ?? false)
                            <img src="{{ \App\Support\Covers::url($t, 64) }}" loading="lazy" @class(['-my-1 size-7 shrink-0 rounded bg-zinc-800 object-cover', 'cover-link' => $canCover]) alt=""
                                 @if ($canCover) onerror="noCover(this)" @click.stop="$dispatch('open-cover-picker', { track: {{ $t->id }} })" title="Cover dieses Lieds ändern" @else onerror="this.style.visibility='hidden'" @endif>
                        @endif
                        <span class="min-w-0 flex-1 truncate">@if ($t->move_to === 'trash')<span class="mr-1.5 rounded bg-red-500/15 px-1.5 text-[11px] font-medium text-red-300" title="wird beim Schreiben in den Papierkorb verschoben (Duplikat)">→ Papierkorb</span>@elseif ($t->move_to)<span class="mr-1.5 rounded bg-amber-500/15 px-1.5 text-[11px] font-medium text-amber-300" title="wird beim Schreiben in die Inbox verschoben">→ Inbox</span>@endif @if ($t->title){{ $t->title }}@else<span class="italic text-zinc-500">{{ $t->filename }}</span>@endif</span>
                        @if ($canPick && ($pickCategory ?? false) && ! isset($columns['category']))@include('livewire.partials.area-picker', ['current' => $t->category, 'method' => 'setCategory', 'arg' => [$t->id]])@endif
                    </div>
                    @break
                @case('compilation'){{ $t->compilation ? '✓' : '' }}@break
                @case('category')@if ($canPick)@include('livewire.partials.category-picker', ['current' => $t->category, 'method' => 'setCategory', 'arg' => [$t->id], 'small' => true, 'model' => null])@else{{ \App\Support\Categories::LABELS[$t->category] ?? '' }}@endif @break
                @case('rating')@include('livewire.partials.stars', ['value' => $t->rating, 'method' => $this->canEdit() ? 'rateTrack' : null, 'arg' => $t->id, 'model' => null, 'large' => false])@break
                @case('duration'){{ $t->durationLabel() }}@break
                @case('format')@if ($t->error)<span class="text-red-400" title="{{ $t->error }}">Fehler</span>@else{{ $t->qualityLabel() }}@endif @break
                @case('created_at'){{ $t->created_at?->format('d.m.Y') }}@break
                @default{{ $value }}
            @endswitch
        </td>
    @endforeach
</tr>
