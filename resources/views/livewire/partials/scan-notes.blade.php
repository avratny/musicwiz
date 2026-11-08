{{-- Hinweise nach einem Scan: Fehler, zurückgehaltenes Entfernen, nicht lesbare Ordner --}}
@php $box = ($pad ?? false) ? 'mx-6 mt-3 shrink-0' : 'mb-4'; @endphp
@if (! ($scan['running'] ?? false))
    @if (! empty($scan['error']) && ($scan['location'] ?? $location) === $location)
        <div class="{{ $box }} rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">Scan-Fehler: {{ $scan['error'] }}</div>
    @endif
    @if (($scan['held']['location'] ?? null) === $location)
        <div class="{{ $box }} flex items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">
            <span class="flex-1">{{ $scan['held']['count'] }} von {{ $scan['held']['known'] }} bekannten Dateien wurden nicht gefunden und deshalb nicht aus dem Index entfernt. War das Share kurz weg, einfach später erneut scannen.</span>
            <button wire:click="startScan('{{ $location }}', true)" wire:confirm="{{ $scan['held']['count'] }} Dateien aus dem Index entfernen? (Die Dateien selbst werden nicht angefasst.)" class="btn-ghost btn-sm shrink-0">Trotzdem entfernen</button>
        </div>
    @endif
    @if (! empty($scan['unreadable']) && ($scan['location'] ?? null) === $location)
        @php
            $count = $scan['unreadable_count'] ?? count($scan['unreadable']);
            $reasons = $scan['unreadable_reasons'] ?? [];
            $summary = collect($scan['unreadable_summary'] ?? [])->sortDesc()->map(fn ($n, $r) => $n.'× '.$r)->implode(', ');
        @endphp
        <div class="{{ $box }} rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200" x-data="{ open: false }">
            {{ $count }} Ordner nicht lesbar{{ $summary ? ' ('.$summary.')' : '' }} – darunter bleibt der Index unverändert.
            <button x-on:click="open = ! open" class="ml-1 text-amber-300 underline decoration-dotted" x-text="open ? 'weniger' : 'Ordner zeigen'">Ordner zeigen</button>
            @if (str_contains($summary, 'keine Berechtigung'))
                <div class="mt-1 text-xs text-amber-300/80">Keine Berechtigung: Der Benutzer, unter dem MusicWiz läuft (PUID/PGID), darf diese Ordner nicht lesen – auf dem NAS die Rechte der Freigabe auf Unterordner anwenden bzw. Besitzer anpassen.</div>
            @endif
            <ul x-show="open" x-cloak class="mt-2 space-y-0.5 text-xs text-amber-300/80">
                @foreach ($scan['unreadable'] as $dir)
                    <li><span class="text-amber-100">{{ $dir }}</span>{{ isset($reasons[$dir]) ? ' – '.$reasons[$dir] : '' }}</li>
                @endforeach
                @if ($count > count($scan['unreadable']))<li>… und {{ $count - count($scan['unreadable']) }} weitere</li>@endif
            </ul>
        </div>
    @endif
    @if (! empty($scan['cancelled']) && ($scan['location'] ?? null) === $location)
        <div class="{{ $box }} rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">
            Scan abgebrochen{{ ($scan['cancelled']['total'] ?? 0) ? ' nach '.$scan['cancelled']['read'].' von '.$scan['cancelled']['total'].' Dateien' : '' }}.
            Bis dahin Gelesenes ist gespeichert; nichts wurde aus dem Index entfernt. Der nächste Scan macht dort weiter.
        </div>
    @endif
    @if (! empty($scan['auto_skipped']) && ($scan['location'] ?? null) === $location)
        <div class="{{ $box }} rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">{{ $scan['auto_skipped'] }}</div>
    @endif
@endif
