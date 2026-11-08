<?php

namespace App\Providers;

use App\Models\Artist;
use App\Models\ArtistAlias;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Artist::class, ArtistAlias::class] as $model) {
            $model::saved(fn () => DataVersion::bumpDirectory());
            $model::deleted(fn () => DataVersion::bumpDirectory());
        }
    }
}
