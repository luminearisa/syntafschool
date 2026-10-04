<?php

namespace App\Modules\StudentProgress\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk modul StudentProgress (Prestasi & Pelanggaran Siswa).
 *
 * Tabel `student_achievements` & `student_violations` sudah dibuat migrasi inti
 * ERP, jadi provider ini hanya memuat rute modul (dan migrasi tambahan bila ada).
 */
class StudentProgressServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
