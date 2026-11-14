{{-- Änderungen: Cover-Wechsel als Bilder (alt: aus der Datei bzw. vorher gesetzt → neu) --}}
<span class="inline-flex items-center gap-2 align-middle">
    @if ($c->old_value)
        <img src="{{ route('cover.image', [$c->old_value, 64]) }}" alt="" class="size-8 rounded bg-zinc-800 object-cover opacity-60" onerror="noCover(this)">
    @else
        <span class="text-xs text-zinc-500">aus der Datei</span>
    @endif
    <span class="text-zinc-600">→</span>
    @if ($c->new_value)
        <img src="{{ route('cover.image', [$c->new_value, 64]) }}" alt="" class="size-8 rounded bg-zinc-800 object-cover" onerror="noCover(this)">
        @if ($image = \App\Models\CoverImage::find($c->new_value))
            <span class="text-xs tabular-nums text-zinc-500">{{ $image->width }}×{{ $image->height }} px</span>
        @endif
    @else
        <span class="text-xs text-zinc-500">aus der Datei</span>
    @endif
</span>
