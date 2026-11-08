<div class="grid grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-5">
    @forelse ($albums as $album)
        <div wire:key="al{{ $album->id }}">
            @if ($blackhole ?? false)
            <button type="button" wire:click="$set('open', {{ $album->id }})" class="group block w-full text-left">
            @else
            <a href="{{ route('library.album', $album) }}" wire:navigate class="group block">
            @endif
                <x-cover :url="$album->coverUrl(400)" class="aspect-square w-full rounded-xl transition group-hover:ring-2 group-hover:ring-blue-500" />
                <div class="mt-2 truncate text-sm font-medium" title="{{ $album->title }}">{{ $album->title }}</div>
                @if ($showArtist ?? true)
                    <div class="truncate text-xs text-zinc-300" title="{{ $album->is_compilation ? 'Sampler' : $album->folder_artist }}">{{ $album->is_compilation ? 'Sampler' : $album->folder_artist }}</div>
                @endif
                <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                    @if ($album->year)<span>{{ $album->year }}</span>·@endif
                    <span>{{ $album->track_count }} Tracks</span>
                    <span class="badge-violet ml-auto scale-90">{{ $album->category }}</span>
                </div>
                @if (isset($busyAlbums[$album->id]))
                    <div class="text-xs text-amber-300">wird in die Inbox verschoben …</div>
                @endif
            @if ($blackhole ?? false)
            </button>
            @else
            </a>
            @endif
            @isset($ratings)
                <x-stars :value="$ratings[$album->id] ?? null" :method="($canRate ?? false) ? 'rateAlbum' : null" :id="$album->id" class="mt-0.5" />
            @endisset
        </div>
    @empty
        <p class="text-sm text-zinc-500">Keine Alben.</p>
    @endforelse
</div>
