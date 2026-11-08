@props(['id', 'title' => '', 'artist' => '', 'album' => '', 'cover' => null, 'start' => 0, 'size' => 'size-7', 'seconds' => null])
@php
    $track = ['id' => (int) $id, 'src' => route('audio', $id), 'title' => (string) $title, 'artist' => (string) $artist, 'album' => (string) $album, 'cover' => $cover, 'seconds' => $seconds !== null ? (float) $seconds : (float) (\App\Models\Track::whereKey($id)->value('duration') ?? 0)];
@endphp
<button type="button" x-data x-on:click.stop="$store.player.play(@js($track), {{ (float) $start }})"
        :class="$store.player.isCurrent({{ (int) $id }}) ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-300 hover:bg-blue-600 hover:text-white'"
        {{ $attributes->merge(['class' => $size.' grid shrink-0 place-items-center rounded-full transition', 'title' => $start > 0 ? 'Anspielen (ab '.round($start * 100).' %)' : 'Abspielen']) }}>
    <svg x-show="!$store.player.isPlaying({{ (int) $id }})" class="size-3.5 translate-x-px" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
    <svg x-show="$store.player.isPlaying({{ (int) $id }})" x-cloak class="size-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
</button>
