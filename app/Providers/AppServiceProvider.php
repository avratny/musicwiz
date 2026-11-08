<?php

namespace App\Providers;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Support\ArtistDirectory;
use App\Support\DataVersion;
use App\Support\Lookup;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // eine Instanz je Anfrage: „Aktuelle Daten laden“ (Lookup::$fresh) gilt dann auch für alle Helfer, die nachschlagen
        $this->app->scoped(Lookup::class);
        // Künstlerverzeichnis einmal je Anfrage bzw. Job laden – vorher holte z. B. PathBuilder::folderName() pro Titel
        // ein neues und las alle Künstler und Schreibweisen erneut (bei großem Verzeichnis zehntausende Abfragen);
        // nach Änderungen leert flush() die gemeinsame Instanz
        $this->app->scoped(ArtistDirectory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Verzeichnis geändert: Versionszähler hoch, und die gemeinsame Instanz lädt beim nächsten Zugriff neu
        $changed = function () {
            DataVersion::bumpDirectory();
            ArtistDirectory::invalidate();
        };
        foreach ([Artist::class, ArtistAlias::class] as $model) {
            $model::saved($changed);
            $model::deleted($changed);
        }
    }
}
