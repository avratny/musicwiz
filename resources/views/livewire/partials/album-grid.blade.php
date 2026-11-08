<div class="grid grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-5">
    @forelse ($albums as $album)
        @if ($blackhole ?? false)
        <button type="button" wire:click="$set('open', {{ $album->id }})" wire:key="al{{ $album->id }}" class="group text-left">
        @else
        <a href="{{ route('library.album', $album) }}" wire:navigate wire:key="al{{ $album->id }}" class="group">
        @endif
            <x-cover :url="$album->coverUrl(400)" class="aspect-square w-full rounded-xl transition group-hover:ring-2 group-hover:ring-blue-500" />
            <div class="mt-2 truncate text-sm font-medium" title="{{ $album->title }}">{{ $album->title }}</div>
            <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                @if ($album->year)<span>{{ $album->year }}</span>·@endif
                <span>{{ $album->track_count }} Tracks</span>
                <span class="badge-violet ml-auto scale-90">{{ $album->category }}</span>
            </div>
        @if ($blackhole ?? false)
        </button>
        @else
        </a>
        @endif
    @empty
        <p class="text-sm text-zinc-500">Keine Alben.</p>
    @endforelse
</div>
