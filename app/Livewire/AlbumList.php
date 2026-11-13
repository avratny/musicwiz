<?php

namespace App\Livewire;

use App\Livewire\Concerns\ShowsMessages;
use App\Models\Track;
use App\Support\AlbumCovers;
use App\Support\AlbumSeries;
use App\Support\IncompleteAlbums;
use App\Support\OpenCounts;
use App\Support\SamplerFlags;
use App\Support\SortNames;
use App\Support\SplitAlbums;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Alben aus Inbox und Lib: links eine Leiste – „Alle“ (Liste aller Alben, auseinandergerissene markiert) und Prüfungen.
 * „Auseinandergerissen“: Alben, die Navidrome wegen uneinheitlicher Tags auf mehrere verteilen würde; je Feld einen
 * Wert fürs ganze Album übernehmen. „Unterschiedliche Cover“ und „Cover zu klein“: Grund und Vorschlag (noch ohne
 * Ausführen – Cover stecken in den Dateien). „Sampler-Kennzeichnung“: Flag und „Various Artists“ passen nicht zu den
 * Interpreten der Lieder – je Album oder alle richten (SamplerFlags). „Sortiernamen“: offene Abweichungen bei
 * ARTISTSORT/ALBUMARTISTSORT (Eindeutiges trägt MusicWiz selbst ein – SortNames). „Unvollständig“: Lücken, Titelliste aus
 * dem Netz, fehlende Titel bei uns suchen und vormerken (IncompleteAlbums). „Serien“: albumübergreifend dasselbe
 * Namensmuster („20 Top Hits aus den Charts 1/99“ → „… 1/1999“) – je Serie übernehmen oder ausnehmen (AlbumSeries).
 */
#[Layout('layouts.app')]
class AlbumList extends Component
{
    use ShowsMessages;

    /** zweite Leiste ('' = alle Alben) */
    public const CHECKS = ['' => 'Alle', 'getrennt' => 'Auseinandergerissen', 'cover-verschieden' => 'Unterschiedliche Cover', 'cover-klein' => 'Cover zu klein', 'sampler' => 'Sampler-Kennzeichnung', 'sortiernamen' => 'Sortiernamen', 'unvollstaendig' => 'Unvollständig', 'serien' => 'Serien'];

    /** Cover-Prüfungen: Eintrag in der Leiste => Prüfung in AlbumCovers */
    private const COVER_CHECKS = ['cover-verschieden' => 'different', 'cover-klein' => 'small'];

    private const STEP = 50;

    #[Url(as: 'ansicht', except: '')]
    public string $check = '';

    #[Url(as: 'q', except: '')]
    public string $query = '';

    public int $limit = self::STEP;

    /** Unvollständig: aufgeklapptes Album (Einzelheiten, Titelliste, Treffer) */
    public ?string $open = null;

    /** Unvollständig: ausgenommene Alben zeigen */
    public bool $exempted = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'check'], true)) {
            $this->limit = self::STEP;
        }
        if ($property === 'check') {
            $this->error = $this->message = null;
        }
    }

    public function align(string $hash, string $field, string $value, SplitAlbums $albums): void
    {
        $changed = $albums->align($hash, $field, $value);
        $this->say(SplitAlbums::FIELDS[$field].' bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').' auf „'.($value === '' ? 'leer' : $value).'“ gesetzt.');
    }

    /** Sampler-Kennzeichnung richten – ein Album bzw. alle (null) */
    public function fixSampler(?string $hash, SamplerFlags $flags): void
    {
        $changed = $flags->apply($hash);
        $this->say('Sampler-Kennzeichnung gerichtet – bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).');
    }

    /** offene Sortiernamen nach der Regel übernehmen – ein Album bzw. alle (null) */
    public function fixSortNames(?string $hash, SortNames $sorts): void
    {
        $changed = $sorts->resolve(fn ($i) => $hash === null || $i['album_hash'] === $hash);
        $this->say('Sortiernamen übernommen – bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).');
    }

    public function toggle(string $hash): void
    {
        $this->open = $this->open === $hash ? null : $hash;
    }

    /** Titelliste aus dem Netz holen (MusicBrainz, Deezer, iTunes) */
    public function fetchTracklist(string $hash, IncompleteAlbums $albums): void
    {
        $this->open = $hash;
        $this->attempt(function () use ($hash, $albums) {
            $list = $albums->fetchTracklist($hash);
            $n = count($list->candidates ?? []);
            $this->message = $n ? $n.' '.($n === 1 ? 'Titelliste' : 'Titellisten').' gefunden'.(IncompleteAlbums::chosen($list) ? ' – passende gewählt.' : ' – keine passt sicher, bitte wählen.')
                : 'Keine Titelliste gefunden.';
            if ($list->errors) {
                $this->message .= ' ('.str_replace("\n", '; ', $list->errors).')';
            }
        });
    }

    public function chooseRelease(string $hash, ?int $index, IncompleteAlbums $albums): void
    {
        $albums->choose($hash, $index);
    }

    /** fehlenden Titel vormerken: kopieren bzw. verschieben (ausgeführt beim Schreiben) */
    public function planTrack(int $trackId, string $hash, int $disc, int $n, string $action, IncompleteAlbums $albums): void
    {
        $this->attempt(function () use ($trackId, $hash, $disc, $n, $action, $albums) {
            $plan = $albums->plan($trackId, $hash, $disc, $n, $action);
            $this->message = 'Nr. '.$n.' vorgemerkt ('.($plan->action === 'move' ? 'verschieben' : 'kopieren').') – ausgeführt beim Schreiben.'
                .($plan->action !== $action ? ' Das Lied wird schon in ein anderes Album verschoben – hierher kommt eine Kopie.' : '');
        });
    }

    public function unplan(int $id, IncompleteAlbums $albums): void
    {
        $albums->unplan($id);
        $this->say('Vormerkung aufgehoben.');
    }

    /** Blackhole-Treffer: erst in die Inbox holen (vormerken) */
    public function toInbox(int $trackId): void
    {
        Track::where('location', 'blackhole')->whereKey($trackId)->update(['move_to' => 'inbox']);
        $this->say('Für die Inbox vorgemerkt – nach dem Schreiben und Einlesen lässt es sich hierher übernehmen.');
    }

    public function fixTotals(string $hash, IncompleteAlbums $albums): void
    {
        $n = $albums->fixTotals($hash);
        $this->say('Titelanzahl korrigiert – bei '.$n.' '.($n === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar).');
    }

    public function exempt(string $hash, bool $exempt, IncompleteAlbums $albums): void
    {
        $albums->exempt($hash, $exempt);
        $this->open = null;
        $this->say($exempt ? 'Ausgenommen – gilt als gewollt unvollständig.' : 'Wird wieder geprüft.');
    }

    /** Serien: auch die schon einheitlichen zeigen (um ein Muster festzulegen) */
    #[Url(as: 'alle', except: false)]
    public bool $allSeries = false;

    /** Serien: Muster festlegen – eins der vorkommenden oder ein eigenes (gilt dauerhaft, auch für neue Alben) */
    public function chooseSeries(string $key, string $pattern, AlbumSeries $series): void
    {
        if ($problem = $series->setPattern($key, $pattern)) {
            $this->error = $problem;
            $this->message = null;

            return;
        }
        $this->say('Muster „'.trim($pattern).'“ festgelegt – gilt auch für Alben, die später dazukommen.');
    }

    public function resetSeries(string $key, AlbumSeries $series): void
    {
        $series->resetPattern($key);
        $this->say('Wieder das häufigste Muster.');
    }

    /** Serien: Muster übernehmen – eine Serie bzw. alle (null) */
    public function fixSeries(?string $key, AlbumSeries $series): void
    {
        $changed = $series->apply($key);
        $this->say('Muster übernommen – bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').' (unter Änderungen zurücksetzbar, Ordner ziehen beim Schreiben mit).');
    }

    public function exemptSeries(string $key, bool $exempt, AlbumSeries $series): void
    {
        $series->exempt($key, $exempt);
        $this->say($exempt ? 'Als „keine Serie“ ausgenommen.' : 'Wird wieder geprüft.');
    }

    /** aus „Alle“: dieses Album unter „Auseinandergerissen“ zeigen */
    public function showSplit(string $hash): void
    {
        $album = SplitAlbums::tracks()->where('album_hash', $hash)->selectRaw('MAX(album) AS album, MIN(album_dir) AS dir')->first();
        $this->check = 'getrennt';
        $this->query = (string) ($album?->album ?: basename((string) $album?->dir));
        $this->limit = self::STEP;
        $this->error = $this->message = null;
    }

    /** Cover gesetzt (Picker als Modal oder in der Karte): Meldung, Listen neu */
    #[On('cover-set')]
    public function coverSet(string $album, string $message): void
    {
        $this->say($message);
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    public function render(SplitAlbums $albums, AlbumCovers $covers, SamplerFlags $flags, SortNames $sorts, IncompleteAlbums $incomplete, AlbumSeries $series)
    {
        $this->check = isset(self::CHECKS[$this->check]) ? $this->check : '';
        // nur Offenes – „Alle“ ist keine Prüfung
        $counts = app(OpenCounts::class)->albums();
        $view = match (true) {
            $this->check === '' => $this->allAlbums($albums),
            $this->check === 'sampler' => $flags->albums($this->query, $this->limit),
            $this->check === 'serien' => $this->seriesView($series),
            $this->check === 'sortiernamen' => $sorts->openAlbums($this->query, $this->limit),
            $this->check === 'unvollstaendig' => [...$incomplete->albums($this->query, $this->limit, $this->exempted),
                'details' => $this->open ? $incomplete->details($this->open) : null],
            isset(self::COVER_CHECKS[$this->check]) => [...$covers->albums(self::COVER_CHECKS[$this->check], $this->query, $this->limit),
                'unread' => $covers->unread(), 'minSize' => $covers->minSize()],
            default => $albums->albums($this->query, $this->limit),
        };

        return view('livewire.album-list', [...$view, 'counts' => $counts])->title('Alben – MusicWiz');
    }

    /** Serien, passend zur Suche (Muster oder Album-Name) */
    private function seriesView(AlbumSeries $series): array
    {
        $needle = mb_strtolower(trim($this->query));
        $match = fn ($s) => $needle === '' || str_contains(mb_strtolower($s['pattern']), $needle)
            || collect($s['changes'])->contains(fn ($c) => str_contains(mb_strtolower($c['from']), $needle));
        $all = $series->series(false, $this->allSeries)->filter($match);

        return ['total' => $all->count(), 'open' => $all->filter(fn ($s) => $s['changes'])->count(), 'series' => $all->take($this->limit)->values(), 'exemptedSeries' => $series->series(true)->filter($match)->values()];
    }

    /** alle Alben: Album-Interpret (wie Navidrome ihn sieht), Album, Jahr, Lieder, Bereich – nach Interpret und Album */
    private function allAlbums(SplitAlbums $albums): array
    {
        $list = SplitAlbums::tracks($this->query)->groupBy('album_hash')
            ->selectRaw('album_hash, MIN(id) AS cover, MIN(location) AS location, MIN(album_dir) AS dir, MAX(album) AS album, MIN(year) AS year, COUNT(*) AS tracks')
            ->selectRaw('MAX('.SplitAlbums::ALBUM_ARTIST_SQL.') AS albumartist')
            ->orderBy('albumartist')->orderBy('album')->orderBy('dir')->limit($this->limit)->get();

        return [
            'total' => SplitAlbums::tracks($this->query)->distinct()->count('album_hash'),
            'albums' => $list,
            'split' => $albums->hashes()->flip(),
        ];
    }
}
