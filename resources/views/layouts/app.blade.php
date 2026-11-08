<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#09090b">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}MusicWiz</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
    @include('partials.player-store')
</head>
<body class="h-dvh overflow-hidden">
<div class="flex h-dvh flex-col overflow-hidden">
<div class="flex min-h-0 flex-1 overflow-hidden">
    {{-- Seitenleiste --}}
    <aside class="flex w-60 shrink-0 flex-col border-r border-zinc-800 bg-zinc-900">
        <a href="{{ route('inbox') }}" wire:navigate class="flex items-center gap-2.5 px-5 py-5">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-blue-500 to-violet-600 shadow-lg shadow-violet-900/40">
                <svg class="size-5 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3v10.55A4 4 0 1 0 11 17V7h6V3H9Z"/></svg>
            </span>
            <span class="text-lg font-semibold tracking-tight">Music<span class="bg-gradient-to-r from-blue-400 to-violet-400 bg-clip-text text-transparent">Wiz</span></span>
        </a>

        @php
            $inboxCount = \App\Models\Album::where('location', 'inbox')->count();
            app(\App\Support\InboxWatch::class)->ensure(); // Beobachter nach Neustart/Abbruch wieder anwerfen (wenn eingeschaltet)
            $ytActive = \App\Models\Operation::where('type', 'download')->whereIn('status', ['pending', 'running'])->get()->reject->isPlanned()->count();
            $nav = [
                ['inbox', 'Inbox', 'M3 13h4l2 3h6l2-3h4M5 5h14l2 8v6H3v-6l2-8Z', $inboxCount],
                ['library', 'Lib', 'M4 4h4v16H4zM10 4h4v16h-4zM16 5l3.5-1 3 15.5-3.5 1z', null],
                ['blackhole', 'Blackhole', 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-5a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', null],
                ['youtube', 'YouTube-Download', 'M12 4v11m0 0-4-4m4 4 4-4M5 20h14', $ytActive ?: null],
                ['trash', 'Papierkorb', 'M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3', app(\App\Support\Trash::class)->count() ?: null],
            ];
            $more = [
                ['artists', 'Künstler', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0', null],
                ['cleanup', 'Aufräumen', 'M5 3v4M3 5h4M6 17v4M4 19h4M13 3l2.3 6.9L22 12l-6.7 2.1L13 21l-2.3-6.9L4 12l6.7-2.1z', null],
                ['flac-candidates', 'FLAC-Kandidaten', 'M3 3h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L21 7H6M10 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z', null],
                ['statistics', 'Statistik', 'M4 20V10M10 20V4M16 20v-7M22 20H2', null],
                ['history', 'Verlauf', 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 7v5l3 2', null],
                ['settings', 'Einstellungen', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2-1.2L14.5 3h-4l-.4 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2 1.2l.4 2.6h4l.4-2.6a7 7 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2Z', null],
            ];
        @endphp

        <nav class="flex-1 space-y-6 px-3">
            @foreach ([$nav, $more] as $group)
                <div class="space-y-1">
                    @foreach ($group as [$route, $label, $icon, $badge])
                        @php $active = request()->routeIs($route) || request()->routeIs($route.'.*'); @endphp
                        <a href="{{ route($route) }}" wire:navigate
                           class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $active ? 'bg-zinc-800 text-white ring-1 ring-zinc-700' : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-100' }}">
                            <svg class="size-5 {{ $active ? 'text-blue-400' : 'text-zinc-500 group-hover:text-zinc-300' }}" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                            <span class="flex-1">{{ $label }}</span>
                            @if ($badge)
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $route === 'trash' ? 'bg-zinc-700 text-zinc-200' : 'bg-violet-500 text-white' }}">{{ $badge }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <livewire:activity />
    </aside>

    {{-- Inhalt --}}
    <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-zinc-950">
        {{ $slot }}
    </main>
</div>

@persist('player')
    <x-player />
@endpersist
</div>
@livewireScripts
</body>
</html>
