@php
    // ein Farbton für alle Mengen-Balken (nur Größe, keine Kategorien); Werte und Beschriftungen in Textfarbe
    $bars = function (array $data, string $unit = '') {
        $max = max($data ?: [1]) ?: 1;
        return collect($data)->map(fn ($n, $label) => ['label' => (string) $label, 'n' => $n, 'w' => max(1, round(100 * $n / $max, 1)), 'unit' => $unit]);
    };
    $fmt = fn ($n) => number_format($n, 0, ',', '.');
@endphp
<div class="flex h-full flex-col overflow-hidden">
    <x-page-header title="Statistik" subtitle="Zahlen zur Lib (ohne Inbox und Blackhole)." />
    <div class="min-h-0 flex-1 overflow-y-auto p-6">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($s['tiles'] as $label => $value)
                <div class="card px-4 py-3">
                    <div class="text-xs text-zinc-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums text-zinc-100">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="card p-4">
                <h2 class="text-sm font-semibold text-zinc-200">Vollständigkeit</h2>
                <p class="text-xs text-zinc-500">Anteil der Titel mit …</p>
                <div class="mt-3 space-y-2.5">
                    @foreach ($s['coverage'] as $label => $pct)
                        <div class="grid grid-cols-[7rem_1fr_3rem] items-center gap-3 text-sm" title="{{ $label }}: {{ $pct }} % der Titel">
                            <span class="text-zinc-400">{{ $label }}</span>
                            <div class="h-2 rounded-full bg-zinc-800"><div class="h-2 rounded-full bg-violet-500" style="width: {{ $pct }}%"></div></div>
                            <span class="text-right tabular-nums text-zinc-300">{{ $pct }} %</span>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card p-4">
                <h2 class="text-sm font-semibold text-zinc-200">Neu in der Lib</h2>
                <p class="text-xs text-zinc-500">Titel je Monat, letzte 12 Monate</p>
                @php $mmax = max($s['months'] ?: [1]) ?: 1; @endphp
                <div class="mt-3 flex h-32 items-end gap-0.5 border-b border-zinc-700">
                    @foreach ($s['months'] as $m => $n)
                        <div class="group relative flex h-full flex-1 items-end justify-center" title="{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->translatedFormat('F Y') }}: {{ $fmt($n) }} Titel">
                            <div class="w-full max-w-6 rounded-t bg-violet-500 group-hover:bg-violet-400" style="height: {{ $n ? max(2, round(100 * $n / $mmax)) : 0 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-0.5 text-[10px] text-zinc-500">
                    @foreach ($s['months'] as $m => $n)
                        <span class="flex-1 text-center">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('m/y') }}</span>
                    @endforeach
                </div>
            </section>

            @foreach ([['Formate', 'Titel je Dateiformat', $s['formats'], 'Titel'], ['MP3-Bitraten', 'Titel je Bitrate (nur MP3)', $s['bitrates'], 'Titel'], ['Genres', 'Alben je Haupt-Genre (Top 12)', $s['genres'], 'Alben'], ['Jahrzehnte', 'Alben je Erscheinungsjahrzehnt', $s['decades'], 'Alben']] as [$title, $sub, $data, $unit])
                <section class="card p-4">
                    <h2 class="text-sm font-semibold text-zinc-200">{{ $title }}</h2>
                    <p class="text-xs text-zinc-500">{{ $sub }}</p>
                    <div class="mt-3 space-y-0.5">
                        @forelse ($bars($data, $unit) as $b)
                            <div class="grid grid-cols-[9rem_1fr_4rem] items-center gap-3 rounded px-1 py-0.5 text-sm hover:bg-zinc-800/60" title="{{ $b['label'] }}: {{ $fmt($b['n']) }} {{ $unit }}">
                                <span class="truncate text-zinc-400">{{ $b['label'] }}</span>
                                <div class="h-3"><div class="h-3 rounded-r bg-violet-500" style="width: {{ $b['w'] }}%"></div></div>
                                <span class="text-right tabular-nums text-zinc-300">{{ $fmt($b['n']) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">Noch keine Daten.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach

            <section class="card p-4 lg:col-span-2">
                <h2 class="text-sm font-semibold text-zinc-200">Kandidaten für FLAC</h2>
                <p class="text-xs text-zinc-500">{{ $fmt($s['mp3_albums']) }} Alben bestehen nur aus verlustbehafteten Dateien – die Künstler mit den meisten davon (ohne Sampler):</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($s['candidates'] as $artist => $n)
                        <a href="{{ route('library', ['artist' => $artist]) }}" wire:navigate class="badge-gray hover:bg-zinc-700">{{ $artist }} · {{ $n }}</a>
                    @empty
                        <p class="text-sm text-zinc-500">Keine – alles verlustfrei.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
