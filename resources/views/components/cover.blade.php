@props(['url' => null, 'class' => 'size-12'])
<div {{ $attributes->merge(['class' => $class.' shrink-0 overflow-hidden rounded-lg bg-zinc-800 ring-1 ring-zinc-700/60']) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" loading="lazy" class="size-full object-cover">
    @else
        <div class="grid size-full place-items-center bg-gradient-to-br from-zinc-800 to-zinc-900 text-zinc-600">
            <svg class="size-1/3" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3v10.55A4 4 0 1 0 11 17V7h6V3H9Z"/></svg>
        </div>
    @endif
</div>
