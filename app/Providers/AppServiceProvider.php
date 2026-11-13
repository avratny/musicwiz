<?php

namespace App\Providers;

use App\Support\ArtistCredits;
use App\Support\GenreTree;
use App\Support\Lookup\Client;
use App\Support\OpenCounts;
use App\Support\SortNames;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Genrebaum einmal je Anfrage bzw. Job laden (nach Änderungen: forget())
        $this->app->singleton(GenreTree::class);
        // je Anfrage bzw. Job einmal: Entscheidungen und bestätigte Namen nicht bei jeder Tag-Änderung neu laden
        $this->app->scoped(ArtistCredits::class);
        // Sortiernamen: eigene Einträge und Artikel einmal je Anfrage bzw. Job (nach Änderungen: forget())
        $this->app->scoped(SortNames::class);
        // ein Client je Anfrage bzw. Auftrag: alle Dienste teilen ihn (Tempo, „frisch fragen“)
        $this->app->scoped(Client::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Uploads (Cover, Interpretenbilder) bis 50 MB – passend zu upload_max_filesize im Dockerfile
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:51200']]);
        // Badges (offen): nach jeder Aktion in der Oberfläche neu rechnen – auch über /api/ui
        \Livewire\on('call', fn () => fn () => OpenCounts::touch());
    }
}
