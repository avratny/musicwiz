@props(['title', 'subtitle' => null])
<header class="flex shrink-0 items-center gap-4 border-b border-zinc-800 bg-zinc-950/80 px-6 py-4 backdrop-blur">
    <div class="min-w-0 flex-1">
        <h1 class="truncate text-xl font-semibold tracking-tight" title="{{ $title }}">{{ $title }}</h1>
        @if ($subtitle)
            <p class="truncate text-sm text-zinc-400" title="{{ $subtitle }}">{{ $subtitle }}</p>
        @endif
    </div>
    {{ $slot }}
</header>
