<?php

namespace App\Modules\Supervisi\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerRateLimiters();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Supervisi\Console\SupervisiRemindCommand::class,
                \App\Modules\Supervisi\Console\SupervisiHealthCommand::class,
                \App\Modules\Supervisi\Console\SupervisiPruneCommand::class,
            ]);
        }
    }

    /**
     * Batas laju modul (mengikuti pola modul LMS).
     *
     * Endpoint verifikasi dokumen bersifat publik, dan pembuatan laporan serta
     * dasbor analitik menjalankan belasan kueri agregat per permintaan — tanpa
     * batas, satu skrip dapat menjatuhkan layanan bagi seluruh sekolah.
     */
    protected function registerRateLimiters(): void
    {
        $limits = (array) config('supervisi.rate_limits', []);

        $limiters = [
            // Jaring pengaman menyeluruh (dipasang pada grup rute).
            'supervisi-module' => ['module', 300],
            'supervisi-public-verify' => ['public_verify', 30],
            'supervisi-report-generate' => ['report_generate', 20],
            'supervisi-analytics' => ['analytics', 60],
            'supervisi-answer-save' => ['answer_save', 240],
            'supervisi-evidence-upload' => ['evidence_upload', 30],
            'supervisi-visit-finalize' => ['visit_finalize', 20],
            'supervisi-search' => ['search', 90],
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
        // Prefix ditulis lengkap karena route modul didaftarkan langsung ke router.
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');

        // Verifikasi dokumen: satu-satunya endpoint tanpa autentikasi.
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/public.php');
    }
}
