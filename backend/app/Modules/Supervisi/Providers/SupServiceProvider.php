<?php

namespace App\Modules\Supervisi\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Supervisi.
 *
 * Mendaftarkan migrasi modul (`sup_*`) dan seluruh route `/api/v1/supervisi/*`.
 * Tidak ada binding kontrak ERP/LMS yang dibutuhkan: modul memakai tabel
 * pegawai & akademik yang sudah ada.
 */
class SupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        // Prefix ditulis lengkap karena route modul didaftarkan langsung ke router.
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
