@php $blackhole = $area === 'blackhole'; @endphp
<div class="flex h-full flex-col overflow-hidden" @if ($scan['running'] ?? false) wire:poll.3s @endif>
    <x-page-header :title="$blackhole ? 'Blackhole' : 'Lib'" :subtitle="$total.' Alben'.($blackhole ? ' – hier wird nichts bearbeitet, nur zurück in die Inbox geholt' : '')">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Interpret, Titel oder Album …" class="input w-72">
        <button wire:click="startScan('{{ $area }}')" class="btn-primary" @disabled($scan['running'] ?? false)>
            <svg class="size-4 {{ ($scan['running'] ?? false) && $scan['location'] === $area ? 'animate-spin' : '' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0 0 12.3 2M18.4 9A7 7 0 0 0 6.1 7"/></svg>
            {{ $blackhole ? 'Blackhole' : 'Lib' }} scannen
        </button>
    </x-page-header>

    <div class="flex shrink-0 flex-wrap items-center gap-1 border-b border-zinc-800 px-6 py-2">
        <button wire:click="setCategory('')" class="{{ $category === '' ? 'tab-active' : 'tab' }}">Alle</button>
        @foreach ($categories as $c)
            <button wire:click="setCategory(@js($c['name']))" class="{{ $category === $c['name'] ? 'tab-active' : 'tab' }}">{{ $c['name'] }}</button>
        @endforeach
    </div>

    @if (! $results && ! ($samplerMode && $letter !== 'sampler' && $category !== ''))
        <div class="flex shrink-0 flex-wrap gap-1 border-b border-zinc-800 px-6 py-2">
            @foreach ($letters as $l => $n)
                <button wire:click="setLetter(@js($l))" title="{{ $n }} Interpreten"
                        class="min-w-8 rounded-md px-2 py-1 text-sm font-medium transition {{ $letter === (string) $l ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:bg-zinc-800 hover:text-white' }}">{{ $l }}</button>
            @endforeach
            @if ($samplerCount)
                <button wire:click="setLetter('sampler')" class="ml-2 rounded-md px-2.5 py-1 text-sm font-medium transition {{ $letter === 'sampler' ? 'bg-violet-600 text-white' : 'text-violet-300 hover:bg-violet-500/20' }}">Sampler · {{ $samplerCount }}</button>
            @endif
        </div>
    @endif

    @include('livewire.partials.scan-notes', ['location' => $area, 'pad' => true])
    @if (session('status'))
        <div class="mx-6 mt-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif

    <div class="flex min-h-0 flex-1 overflow-hidden">
        @if ($results)
            <div class="min-h-0 flex-1 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead><tr><th class="th w-10"></th><th class="th">Interpret</th><th class="th">Titel</th><th class="th">Album</th><th class="th">Kategorie</th>@if ($blackhole)<th class="th w-36"></th>@endif</tr></thead>
                    <tbody>
                    @forelse ($results as $t)
                        <tr class="cursor-pointer hover:bg-zinc-900" wire:key="t{{ $t->id }}"
                            @if ($blackhole) wire:click="$set('open', {{ (int) $t->album_id }})" @else onclick="Livewire.navigate('{{ route('library.album', $t->album_id) }}')" @endif>
                            <td class="td"><x-play-button :id="$t->id" :seconds="$t->duration" :title="$t->title" :artist="$t->artist" :album="$t->album" :cover="$t->albumRecord?->coverUrl(120)" /></td>
                            <td class="td">{{ $t->artist }}</td>
                            <td class="td">{{ $t->title }}</td>
                            <td class="td text-zinc-400">{{ $t->album }}</td>
                            <td class="td"><span class="badge-violet">{{ $t->albumRecord?->category }}</span></td>
                            @if ($blackhole)
                                <td class="td text-right"><button wire:click.stop="trackToInbox({{ $t->id }})" class="btn-ghost btn-sm">→ In die Inbox</button></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="td py-10 text-center text-zinc-500">Nichts gefunden.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($samplerMode)
            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                @include('livewire.partials.album-grid', ['albums' => $albums, 'blackhole' => $blackhole])
            </div>
        @else
            <div class="w-72 shrink-0 overflow-y-auto border-r border-zinc-800 p-2">
                @forelse ($artists as $a)
                    <button wire:click="$set('artist', @js($a->folder_artist))" wire:key="ar{{ md5($a->folder_artist) }}"
                            class="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm transition {{ $artist === $a->folder_artist ? 'bg-blue-600/20 text-blue-100 ring-1 ring-blue-500/40' : 'text-zinc-300 hover:bg-zinc-800' }}">
                        <span class="flex-1 truncate" title="{{ $a->folder_artist }}">{{ $a->folder_artist }}</span>
                        <span class="text-xs text-zinc-500">{{ $a->n }}</span>
                    </button>
                @empty
                    <p class="p-4 text-sm text-zinc-500">{{ $letter === '' ? 'Buchstaben wählen.' : 'Keine Interpreten.' }}</p>
                @endforelse
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                @if ($artist !== '')
                    <div class="mb-6 flex items-center gap-4">
                        @if ($artistModel?->imageUrl())
                            <img src="{{ $artistModel->imageUrl() }}" class="size-20 rounded-full object-cover ring-2 ring-violet-500/50">
                        @else
                            <div class="grid size-20 place-items-center rounded-full bg-gradient-to-br from-blue-600/40 to-violet-600/40 text-2xl font-semibold">{{ mb_substr($artist, 0, 1) }}</div>
                        @endif
                        <div>
                            <h2 class="text-2xl font-semibold">{{ $artist }}</h2>
                            <p class="text-sm text-zinc-400">{{ $albums->count() }} Alben @if ($artistModel?->default_genre) · {{ $artistModel->default_genre }} @endif</p>
                        </div>
                    </div>
                    @include('livewire.partials.album-grid', ['albums' => $albums, 'blackhole' => $blackhole])
                @else
                    <div class="grid h-full place-items-center text-zinc-500">Buchstabe → Interpret → Album</div>
                @endif
            </div>
        @endif
    </div>

    @if ($blackhole && ($openAlbum ?? null))
        {{-- Blackhole: Titelliste statt Editor --}}
        <div class="fixed inset-0 z-40 grid place-items-center bg-black/60 p-6" wire:click.self="$set('open', null)">
            <div class="card flex max-h-full w-full max-w-3xl flex-col overflow-hidden">
                <div class="flex items-center gap-4 border-b border-zinc-800 p-4">
                    <x-cover :url="$openAlbum->coverUrl(120)" class="size-16 shrink-0 rounded-lg" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-lg font-semibold">{{ $openAlbum->title }}</div>
                        <div class="truncate text-sm text-zinc-400">{{ $openAlbum->is_compilation ? $openAlbum->category : $openAlbum->folder_artist }} @if ($openAlbum->year)· {{ $openAlbum->year }}@endif · {{ $openAlbum->track_count }} Tracks</div>
                    </div>
                    <button wire:click="albumToInbox({{ $openAlbum->id }})" wire:confirm="Album „{{ $openAlbum->title }}“ in die Inbox verschieben?" class="btn-primary btn-sm">Album → In die Inbox</button>
                    <button wire:click="$set('open', null)" class="btn-ghost btn-sm" title="Schließen">✕</button>
                </div>
                <div class="min-h-0 overflow-y-auto">
                    <table class="w-full text-sm">
                        <tbody>
                        @foreach ($openTracks as $t)
                            <tr wire:key="ot{{ $t->id }}" class="hover:bg-zinc-900">
                                <td class="td w-10"><x-play-button :id="$t->id" :seconds="$t->duration" :title="$t->title" :artist="$t->artist" :album="$t->album" :cover="$openAlbum->coverUrl(120)" /></td>
                                <td class="td w-12 text-zinc-500">{{ $t->track }}</td>
                                <td class="td">{{ $t->title }}<div class="text-xs text-zinc-500">{{ $t->artist }}</div></td>
                                <td class="td w-16 text-zinc-500">{{ $t->durationLabel() }}</td>
                                <td class="td w-36 text-right"><button wire:click="trackToInbox({{ $t->id }})" class="btn-ghost btn-sm">→ In die Inbox</button></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
