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
        <div class="{{ $box }} rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">
            {{ count($scan['unreadable']) }} Ordner nicht lesbar – darunter bleibt der Index unverändert:
            <span class="text-amber-300/80">{{ implode(', ', array_slice($scan['unreadable'], 0, 5)) }}{{ count($scan['unreadable']) > 5 ? ' …' : '' }}</span>
        </div>
    @endif
    @if (! empty($scan['auto_skipped']) && ($scan['location'] ?? null) === $location)
        <div class="{{ $box }} rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">{{ $scan['auto_skipped'] }}</div>
    @endif
@endif
