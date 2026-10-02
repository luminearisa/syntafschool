<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * HRD Phase 3 — lapisan strategis: perencanaan tenaga kerja, skills
 * intelligence, talent & succession intelligence, people analytics,
 * forecasting, dan AI/agent orchestration.
 *
 * Provider ini TIDAK membuat ulang data Phase 1/2. Ia membaca employee,
 * posisi, organisasi, presensi, cuti, payroll, kinerja, pembelajaran,
 * keterampilan, karier, dan talenta yang sudah ada, lalu mengubahnya menjadi
 * wawasan, proyeksi, dan rekomendasi yang dapat dieksekusi.
 */
class HrdPhase3ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/hrd_phase3.php'), 'hrd_phase3');

        $this->registerDomainBindings();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Strategic/Database/Migrations');

        RateLimiter::for('hrd-phase3', function (Request $request): Limit {
            $key = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute((int) config('hrd_phase3.rate_limit', 90))->by($key);
        });

        Route::middleware(['api', 'auth:sanctum', 'throttle:hrd-phase3'])
            ->prefix('api/v1/hr')
            ->group(function (): void {
                require __DIR__.'/../Strategic/Routes/phase3.php';
            });
    }

    /**
     * Kontrak → implementasi. Setiap kontrak memiliki implementasi bawaan di
     * modul ini; modul lain dapat menggantinya tanpa menyentuh layanan HRD.
     */
    private function registerDomainBindings(): void
    {
        $bindings = config('hrd_phase3.bindings', []);

        foreach ((array) $bindings as $contract => $implementation) {
            // Kontrak adalah interface: class_exists() akan false untuk interface.
            $contractExists = interface_exists($contract) || class_exists($contract);
            if (! $contractExists || ! class_exists($implementation)) {
                continue;
            }

            $this->app->bind($contract, $implementation);
        }
    }
}
