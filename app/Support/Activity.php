<?php

namespace App\Support;

use App\Models\ActivityRecord;

/**
 * Hintergrundvorgänge für die Leiste über dem Player – mehrere gleichzeitig (Einlesen, Abgleich …), je Vorgang ein
 * Schlüssel (z. B. „scan“, „lookup“).
 */
class Activity
{
    /** abgeschlossene Vorgänge bleiben so lange sichtbar (Sekunden) */
    private const SHOW_FINISHED = 12;

    public static function start(string $key, string $label, int $total = 0, ?string $detail = null): void
    {
        ActivityRecord::updateOrCreate(['key' => $key], ['label' => $label, 'done' => 0, 'total' => $total, 'running' => true,
            'detail' => $detail, 'message' => null, 'started_at' => now(), 'finished_at' => null]);
    }

    public static function progress(string $key, int $done, ?int $total = null, ?string $label = null, ?string $detail = null): void
    {
        ActivityRecord::where('key', $key)->update(array_filter(['done' => $done, 'total' => $total, 'label' => $label, 'detail' => $detail,
            'updated_at' => now()], fn ($v) => $v !== null));
    }

    public static function finish(string $key, string $message): void
    {
        ActivityRecord::where('key', $key)->update(['running' => false, 'message' => mb_substr($message, 0, 255), 'detail' => null, 'finished_at' => now()]);
    }

    /** Stand eines Vorgangs */
    public static function current(string $key): array
    {
        $a = ActivityRecord::find($key);

        return $a ? self::toArray($a) : ['key' => $key, 'label' => null, 'done' => 0, 'total' => 0, 'running' => false, 'detail' => null, 'message' => null];
    }

    public static function running(?string $key = null): bool
    {
        return ActivityRecord::where('running', true)->when($key, fn ($q) => $q->where('key', $key))->exists();
    }

    /** Für die Leiste: laufende und gerade beendete Vorgänge */
    public static function visible(): array
    {
        return ActivityRecord::where('running', true)->orWhere('finished_at', '>=', now()->subSeconds(self::SHOW_FINISHED))
            ->orderBy('started_at')->get()->map(fn ($a) => self::toArray($a))->values()->all();
    }

    private static function toArray(ActivityRecord $a): array
    {
        return ['key' => $a->key, 'label' => $a->label, 'done' => (int) $a->done, 'total' => (int) $a->total, 'running' => $a->running,
            'detail' => $a->detail, 'message' => $a->message, 'finished_at' => $a->finished_at?->toIso8601String()];
    }
}
