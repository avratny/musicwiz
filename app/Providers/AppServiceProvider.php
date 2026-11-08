<?php

namespace App\Providers;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Support\DataVersion;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
