<?php

namespace App\Modules\UKS\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul UKS.
 *
 * Mendaftarkan migrasi `uks_*`, batas laju, dan seluruh route `/api/v1/uks/*`.
 * Modul memakai tabel siswa, pegawai, wali murid, dan kelas yang sudah ada —
 * tidak ada kontrak ERP baru yang perlu diikat.
 */
class UksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/uks.php', 'uks');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\UKS\Console\UksRemindCommand::class,
                \App\Modules\UKS\Console\UksHealthCommand::class,
            ]);
        }
    }

    protected function registerRateLimiters(): void
    {
        $limits = (array) config('uks.rate_limits', []);

        $limiters = [
            'uks-module' => ['module', 300],
            'uks-public-verify' => ['public_verify', 30],
            'uks-search' => ['search', 90],
            'uks-visit-write' => ['visit_write', 120],
            'uks-report' => ['report', 20],
        ];

        foreach ($limiters as $name => [$key, $default]) {
            $perMinute = max(1, (int) ($limits[$key] ?? $default));

            RateLimiter::for($name, function (Request $request) use ($perMinute): Limit {
                $identifier = $request->user()?->getAuthIdentifier();

                return Limit::perMinute($perMinute)
                    ->by($identifier !== null ? 'user:'.$identifier : 'ip:'.$request->ip());
            });
        }
    }

    protected function registerRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/public.php');
    }
}
