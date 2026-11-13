<?php

namespace Tests\Concerns;

use App\Support\MusicBrainzData;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** Kleine MusicBrainz-Dumps wie die echten (LATEST, je Stand artist/release-group.tar.xz mit mbdump/… und TIMESTAMP). */
trait MakesMusicBrainzDumps
{
    protected ?string $musicBrainzDir = null;

    /** Dump schreiben (Stand $stand wird LATEST) und config auf das Verzeichnis zeigen lassen */
    protected function musicBrainzDump(array $artists, array $releaseGroups = [], string $stand = '20261111-001001'): string
    {
        $dir = $this->musicBrainzDir ??= storage_path('framework/testing/mb-'.bin2hex(random_bytes(4)));
        foreach (['artist' => $artists, 'release-group' => $releaseGroups] as $kind => $rows) {
            $src = $dir.'/src-'.$kind;
            File::ensureDirectoryExists($src.'/mbdump');
            File::ensureDirectoryExists($dir.'/'.$stand);
            file_put_contents($src.'/TIMESTAMP', '2026-11-11 00:10:01.123+00');
            file_put_contents($src.'/mbdump/'.$kind, implode("\n", array_map('json_encode', $rows))."\n");
            Process::run(['tar', '-cJf', $dir.'/'.$stand.'/'.$kind.'.tar.xz', '-C', $src, 'TIMESTAMP', 'mbdump'])->throw();
            File::deleteDirectory($src);
        }
        file_put_contents($dir.'/LATEST', $stand."\n");
        config(['musicwiz.musicbrainz_dumps' => 'file://'.$dir.'/']);

        return $dir;
    }

    /** Dump schreiben und laden */
    protected function loadMusicBrainz(array $artists, array $releaseGroups = []): void
    {
        $this->musicBrainzDump($artists, $releaseGroups);
        app(MusicBrainzData::class)->update(force: true);
    }

    protected function tearDownMusicBrainzDumps(): void
    {
        if ($this->musicBrainzDir) {
            File::deleteDirectory($this->musicBrainzDir);
        }
    }
}
