<?php

namespace App\Livewire;

use App\Models\CoverImage;
use App\Models\FolderImage;
use App\Models\Track;
use App\Support\AlbumCovers;
use App\Support\Albums;
use App\Support\Covers;
use App\Support\CoverStore;
use App\Support\Lookup\CoverSuggestions;
use App\Support\NameRules;
use App\Support\SplitAlbums;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Cover für ein Album setzen: Vorschläge aus dem Album selbst (eingebettete Fassungen, Bild im Ordner), aus dem Netz
 * (Cover Art Archive, Deezer, iTunes), hochgeladen oder per URL. Als Modal (Event „open-cover-picker“ mit album = Album-Hash
 * oder track = Lied, z. B. beim Klick auf ein Cover) oder
 * eingebettet (inline, z. B. in „Cover zu klein“ – dort wird erst auf Knopfdruck im Netz gesucht). Liegt das Lied in
 * einem Ordner mit Einzeltiteln (kein gemeinsames Album), gilt das Cover nur für dieses Lied. Gesetzt wird nur in der
 * Datenbank (Tag-Änderung „cover“); danach kommt „cover-set“.
 */
class CoverPicker extends Component
{
    use WithFileUploads;

    public ?string $album = null;

    /** nur dieses Lied (Einzeltitel) statt des ganzen Albums */
    public ?int $track = null;

    public bool $inline = false;

    public bool $open = false;

    /** Mindestgröße (kürzere Seite, z. B. in „Cover zu klein“): kleinere Vorschläge ausblenden, kleinere Bilder ablehnen */
    public int $min = 0;

    public array $suggestions = [];

    public array $searchErrors = [];

    public bool $searched = false;

    public string $url = '';

    /** hochgeladenes Bild (Dateiauswahl oder hineingezogen) – wird sofort übernommen */
    public $upload = null;

    public ?string $error = null;

    #[On('open-cover-picker')]
    public function show(?string $album = null, ?int $track = null): void
    {
        if ($this->inline) {
            return;
        }
        $this->album = $album ?? ($track ? Track::whereKey($track)->value('album_hash') : null);
        // Klick aufs Cover eines Einzeltitels: nur dieses Lied
        $this->track = $album === null && $track && $this->album && Albums::loose(Track::where('album_hash', $this->album)->get(['album', 'album_dir'])) ? $track : null;
        $this->reset('suggestions', 'searchErrors', 'searched', 'url', 'upload', 'error');
        // nur Alben in Inbox und Lib
        if (! $this->info()) {
            return;
        }
        $this->open = true;
        $this->search();
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function search(): void
    {
        $info = $this->info();
        if (! $info) {
            return;
        }
        $result = app(CoverSuggestions::class)->search($info['album'], $info['artist'] === NameRules::VARIOUS ? '' : $info['artist'], $info['mb_albumid']);
        $this->suggestions = $result['items'];
        $this->searchErrors = $result['errors'];
        $this->searched = true;
    }

    /** Bild per URL (Eingabefeld oder Vorschlag aus dem Netz) */
    public function useUrl(?string $url = null): void
    {
        $this->attemptApply(fn (CoverStore $store) => $store->fromUrl($url ?? $this->url));
    }

    public function updatedUpload(): void
    {
        $this->resetErrorBag('upload');
        if (! $this->upload) {
            return;
        }
        $file = $this->upload;
        $this->upload = null;
        $this->attemptApply(fn (CoverStore $store) => $store->fromUpload($file));
    }

    /** eingebettete Fassung eines Lieds aus dem Album */
    public function useTrack(int $id): void
    {
        $this->attemptApply(fn (CoverStore $store) => $store->fromTrack(Track::where('album_hash', $this->album)->when($this->track, fn ($q) => $q->whereKey($this->track))->findOrFail($id)));
    }

    /** Bild im Ordner des Albums */
    public function useFolder(int $id): void
    {
        $this->attemptApply(fn (CoverStore $store) => $store->fromFolderImage(FolderImage::findOrFail($id)));
    }

    private function attemptApply(callable $load): void
    {
        $this->error = null;
        $store = app(CoverStore::class);
        try {
            $image = $load($store);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }
        if ($this->min && min($image->width, $image->height) < $this->min) {
            $this->error = 'Das Bild hat nur '.$image->width.'×'.$image->height.' px – kleiner als '.$this->min.' px, nicht gesetzt.';

            return;
        }
        $changed = $this->track ? $store->applyTracks([$this->track], $image) : $store->apply((string) $this->album, $image);
        $this->open = false;
        $this->url = '';
        $this->dispatch('cover-set', album: $this->album, message: 'Cover gesetzt ('.$image->width.'×'.$image->height.' px) – bei '.$changed.' '.($changed === 1 ? 'Lied' : 'Liedern').', noch nicht in den Dateien.');
    }

    /** Album: häufigster Name und Album-Interpret, MusicBrainz-Album-ID */
    private function info(): ?array
    {
        if (! $this->album) {
            return null;
        }
        $tracks = Track::where('album_hash', $this->album)->whereIn('location', SplitAlbums::LOCATIONS)->when($this->track, fn ($q) => $q->whereKey($this->track))
            ->get(['id', 'dir', 'title', 'album', 'albumartist', 'artist', 'compilation', 'mb_albumid', 'has_cover', 'cover_width', 'cover_height', 'cover_phash', 'cover']);
        if ($tracks->isEmpty()) {
            return null;
        }
        $top = fn ($values) => (string) $values->filter()->countBy()->sortDesc()->keys()->first();

        return [
            'tracks' => $tracks,
            'single' => $this->track !== null,
            // Einzeltitel ohne Album: nach dem Titel suchen (Single-Cover)
            'album' => $top($tracks->pluck('album')) ?: ($this->track ? (string) $tracks->first()->title : ''),
            'artist' => $top($tracks->map(fn ($t) => $t->albumartist ?? ($t->compilation ? NameRules::VARIOUS : $t->artist))),
            'mb_albumid' => $top($tracks->pluck('mb_albumid')) ?: null,
        ];
    }

    public function render()
    {
        $info = ($this->open || $this->inline) ? $this->info() : null;
        $local = [];
        if ($info) {
            $min = app(AlbumCovers::class)->minSize();
            // gesetzte Cover, eingebettete Fassungen (je Größe und Motiv eine), Bilder im Ordner
            foreach (CoverImage::whereIn('hash', $info['tracks']->pluck('cover')->filter()->unique())->get() as $c) {
                $local[] = ['kind' => 'new', 'label' => 'neu gesetzt', 'preview' => route('cover.image', [$c->hash, 300]), 'width' => $c->width, 'height' => $c->height,
                    'count' => $info['tracks']->where('cover', $c->hash)->count(), 'id' => null];
            }
            foreach ($info['tracks']->filter(fn ($t) => $t->cover_phash)->groupBy(fn ($t) => $t->cover_phash.'-'.$t->cover_width.'x'.$t->cover_height) as $group) {
                $t = $group->first();
                $local[] = ['kind' => 'embedded', 'label' => 'eingebettet', 'preview' => Covers::url($t->id, 300, true), 'width' => $t->cover_width,
                    'height' => $t->cover_height, 'count' => $group->count(), 'id' => $t->id];
            }
            foreach (FolderImage::whereIn('dir', $info['tracks']->pluck('dir')->unique())->whereNotNull('width')->get() as $i) {
                $local[] = ['kind' => 'folder', 'label' => basename($i->path), 'preview' => route('folder.image', [$i->id, 300, 'v' => $i->phash]), 'width' => $i->width,
                    'height' => $i->height, 'count' => null, 'id' => $i->id];
            }
        }

        // Mindestgröße: nur, was das Problem löst (Netz: unbekannte Größe bleibt, wird beim Übernehmen geprüft)
        $hidden = 0;
        if ($this->min) {
            $all = count($local) + count($this->suggestions);
            $local = array_values(array_filter($local, fn ($l) => $l['kind'] !== 'new' && min($l['width'], $l['height']) >= $this->min));
            $suggestions = array_values(array_filter($this->suggestions, fn ($s) => ($s['max'] ?? PHP_INT_MAX) >= $this->min));
            $hidden = $all - count($local) - count($suggestions);
        }

        return view('livewire.cover-picker', ['info' => $info, 'local' => $local, 'shownSuggestions' => $suggestions ?? $this->suggestions,
            'hidden' => $hidden, 'minSize' => $this->min ?: ($min ?? 0)]);
    }
}
