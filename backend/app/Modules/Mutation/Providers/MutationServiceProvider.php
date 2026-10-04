<?php

namespace App\Modules\Mutation\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk modul Mutation (Mutasi Siswa: masuk, keluar, pindah kelas/sekolah).
 *
 * Modul ini membawa tabelnya sendiri (`student_transfers`) sehingga provider
 * memuat migrasi modul sekaligus rutenya.
 */
class MutationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
