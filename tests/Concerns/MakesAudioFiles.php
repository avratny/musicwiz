<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** Echte kleine Audiodateien mit Tags für Tests (braucht python3-mutagen und ffmpeg – läuft im Container). */
trait MakesAudioFiles
{
    protected string $base;

    protected function setUpMusicFolders(): void
    {
        $this->base = storage_path('framework/testing/music-'.bin2hex(random_bytes(4)));
        foreach (['inbox', 'library', 'blackhole', 'trash'] as $location) {
            File::ensureDirectoryExists($this->base.'/'.$location);
            config(['musicwiz.locations.'.$location => $this->base.'/'.$location]);
        }
        config(['musicwiz.scan_workers' => 2]);
        // Zwischenspeicher im Testordner – nie die echten (Entwicklung) anfassen
        config(['musicwiz.cover_cache' => $this->base.'/_cache/covers', 'musicwiz.cover_store' => $this->base.'/_cache/cover-store', 'musicwiz.preview_dir' => $this->base.'/_cache/previews']);
    }

    protected function tearDownMusicFolders(): void
    {
        File::deleteDirectory($this->base);
    }

    /**
     * MP3 mit ID3-Frames, z. B. ['TIT2' => 'Titel', 'TXXX:EIGEN' => 'Wert', 'COMM' => 'Kommentar', 'APIC' => $this->jpeg(), 'POPM' => 196].
     */
    protected function mp3(string $rel, array $frames = []): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 80));
        $this->tag($path, 'mp3', $frames);

        return $path;
    }

    /** FLAC (2 s – so lang wie die Test-MP3) mit Vorbis-Kommentaren, z. B. ['TITLE' => 'Titel']; höhere Abtastrate = bessere Fassung. */
    protected function flac(string $rel, array $fields = [], int $rate = 44100): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r='.$rate.':cl=mono', '-t', '2', $path])->throw();
        $this->tag($path, 'flac', $fields);

        return $path;
    }

    /** Kleines JPEG (Cover), Pfad */
    protected function jpeg(string $rel = 'cover-src.jpg', int $size = 400): string
    {
        $path = $this->base.'/'.$rel;
        File::ensureDirectoryExists(dirname($path));
        $img = imagecreatetruecolor($size, $size);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 40, 40));
        imagejpeg($img, $path);

        return $path;
    }

    protected function tag(string $path, string $kind, array $fields): void
    {
        $script = <<<'PY'
import json, sys
from mutagen.flac import FLAC
from mutagen.id3 import ID3, ID3NoHeaderError, Frames, TXXX, COMM, APIC, POPM
path, kind, fields = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
if kind == "flac":
    f = FLAC(path)
    if f.tags is None:
        f.add_tags()
    for k, v in fields.items():
        f.tags[k] = v if isinstance(v, list) else [v]
    f.save()
else:
    try:
        t = ID3(path)
    except ID3NoHeaderError:
        t = ID3()
    for k, v in fields.items():
        if k.startswith("TXXX:"):
            t.add(TXXX(encoding=3, desc=k[5:], text=v if isinstance(v, list) else [v]))
        elif k == "COMM":
            t.add(COMM(encoding=3, lang="eng", desc="", text=[v]))
        elif k == "POPM":
            t.add(POPM(email="no@email", rating=int(v), count=0))
        elif k == "APIC":
            t.add(APIC(encoding=3, mime="image/jpeg", type=3, desc="", data=open(v, "rb").read()))
        else:
            t.add(Frames[k](encoding=3, text=[v] if isinstance(v, str) else v))
    t.save(path, v2_version=4)
PY;
        Process::run(['python3', '-c', $script, $path, $kind, json_encode($fields)])->throw();
    }
}
