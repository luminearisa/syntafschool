<?php

namespace App\Modules\Promotion\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk modul Promotion (A10 — Kenaikan Kelas & Kelulusan).
 *
 * Tabel `promotion_rules`, `promotion_decisions`, `promotion_approvals`, dan
 * `graduations` sudah dibuat oleh migrasi inti ERP di `database/migrations`,
 * jadi provider ini hanya memuat rute modul (dan migrasi tambahan modul bila ada).
 */
class PromotionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
