<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Seiten der Oberfläche über die Schnittstelle: eine Livewire-Komponente aufbauen, Eigenschaften setzen, Aktionen
 * aufrufen – genau wie ein Klick in der App (gleicher Code, gleiche Prüfungen). Zurück kommen der Stand der
 * Komponente, Rückgabewerte, ausgelöste Ereignisse und die Seite als Text.
 */
class ApiUi
{
    /** Methoden, die Livewire selbst aufruft – keine Aktionen */
    private const HOOKS = '/^(mount|render|boot|booted|hydrate|dehydrate|updating|updated|rendering|rendered|exception)/';

    /** @return array<string, array{class: class-string, description: ?string, mount: list<string>, actions: array<string, array{params: list<string>, description: ?string}>}> */
    public static function components(): array
    {
        $out = [];
        foreach (glob(app_path('Livewire/*.php')) as $file) {
            $class = 'App\\Livewire\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            $actions = [];
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isStatic() || $m->getDeclaringClass()->getName() !== $class || preg_match(self::HOOKS, $m->getName())) {
                    continue;
                }
                $actions[$m->getName()] = ['params' => self::params($m), 'description' => self::summary($m->getDocComment())];
            }
            $out[Str::kebab($ref->getShortName())] = ['class' => $class, 'description' => self::summary($ref->getDocComment()),
                'mount' => $ref->hasMethod('mount') ? self::params($ref->getMethod('mount')) : [], 'actions' => $actions];
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $mount  Parameter für mount (z. B. slug)
     * @param  array<string, mixed>  $set  Eigenschaft (auch „form.title“) => Wert, vor den Aufrufen
     * @param  list<array{method: string, params?: list<mixed>}>  $calls
     */
    public function run(string $name, array $mount = [], array $set = [], array $calls = []): array
    {
        $component = self::components()[$name] ?? throw new InvalidArgumentException('Unbekannte Seite: '.$name);
        foreach ($calls as $i => $call) {
            if (! isset($component['actions'][$call['method'] ?? ''])) {
                throw new InvalidArgumentException('Unbekannte Aktion: '.($call['method'] ?? '(leer)'));
            }
            $calls[$i] = ['method' => $call['method'], 'params' => array_values((array) ($call['params'] ?? []))];
        }
        $html = Livewire::mount($component['class'], $mount);
        preg_match('/wire:snapshot="([^"]*)"/', $html, $m);
        $snapshot = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
        $effects = [];
        if ($set || $calls) {
            [$snapshot, $effects] = Livewire::update($snapshot, $set, $calls);
            $html = $effects['html'] ?? $html;
        }

        return [
            'data' => self::plain($snapshot['data'] ?? []),
            'errors' => $snapshot['memo']['errors'] ?? [],
            'returns' => $effects['returns'] ?? [],
            'dispatches' => array_map(fn ($d) => ['name' => $d['name'], 'params' => $d['params'] ?? []], $effects['dispatches'] ?? []),
            'redirect' => $effects['redirect'] ?? null,
            'html' => $html,
        ];
    }

    /** sichtbarer Text einer Seite – Zeilen je Block, Zellen mit „ | “ getrennt */
    public static function text(string $html): string
    {
        $html = preg_replace('#<(script|style|svg)\b.*?</\1>#is', '', $html);
        // Tabellenzeilen in eine Zeile: Zellen mit „ | “
        $html = preg_replace_callback('#<tr\b.*?</tr>#is', function ($row) {
            preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $row[0], $cells);

            return implode(' | ', array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<[^>]+>#', ' $0', $c)))), $cells[1]))."\n";
        }, $html);
        $html = preg_replace('#<br\s*/?>|</(div|p|li|tr|h\d|section|header|footer|table|ul|ol|label|button|option)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $lines = array_map(fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l), ' |'), explode("\n", $text));

        return implode("\n", array_values(array_filter($lines, fn ($l) => $l !== '')));
    }

    /** Werte aus dem Livewire-Snapshot ohne Typ-Angaben ([wert, {s: …}] → wert) */
    private static function plain(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (count($value) === 2 && array_is_list($value) && is_array($value[1]) && isset($value[1]['s'])) {
            return self::plain($value[0]);
        }

        $out = [];
        foreach ($value as $key => $v) {
            // Zugangsdaten (Einstellungen) nie herausgeben
            $out[$key] = isset(Settings::SECRETS[$key]) && is_string($v = self::plain($v)) ? ($v === '' ? '' : '***') : self::plain($v);
        }

        return $out;
    }

    /** z. B. „hash: ?string“ – nur Werte, die man übergibt (Dienste wie TagEditor setzt Laravel selbst ein) */
    private static function params(ReflectionMethod $m): array
    {
        $out = [];
        foreach ($m->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                continue;
            }
            $out[] = $p->getName().($type ? ': '.$type : '').($p->isDefaultValueAvailable() ? ' = '.json_encode($p->getDefaultValue()) : '');
        }

        return $out;
    }

    private static function summary(string|false $doc): ?string
    {
        if (! $doc) {
            return null;
        }
        $text = trim(preg_replace('#^\s*\*\s?#m', '', preg_replace('#^/\*\*|\*/$#', '', trim($doc))));

        return trim(preg_replace('/\s+/u', ' ', preg_split('/\n\s*\n|\n\s*@/', $text)[0])) ?: null;
    }
}
