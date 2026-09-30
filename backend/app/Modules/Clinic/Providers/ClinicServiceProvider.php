<?php

namespace App\Modules\Clinic\Providers;

use App\Modules\Clinic\Domain\Contracts\AssetProviderInterface;
use App\Modules\Clinic\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Clinic\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Clinic\Domain\Contracts\DiagnosisCodeProviderInterface;
use App\Modules\Clinic\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Clinic\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Clinic\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Clinic\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Infrastructure\Providers\DatabaseNotificationProvider;
use App\Modules\Clinic\Infrastructure\Providers\EloquentAttendanceProvider;
use App\Modules\Clinic\Infrastructure\Providers\EloquentBoardingProvider;
use App\Modules\Clinic\Infrastructure\Providers\EloquentParentProvider;
use App\Modules\Clinic\Infrastructure\Providers\EloquentStudentProvider;
use App\Modules\Clinic\Infrastructure\Providers\InternalDiagnosisCodeProvider;
use App\Modules\Clinic\Infrastructure\Providers\InventoryAssetProvider;
use App\Modules\Clinic\Infrastructure\Providers\InventoryItemProvider;
use App\Modules\Clinic\Infrastructure\Providers\LocalDocumentProvider;
use App\Modules\Clinic\Infrastructure\Providers\NullFinanceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Clinic / UKS.
 *
 * Mendaftarkan: migrasi `clinic_*`, konfigurasi, batas laju, perintah artisan,
 * route `/api/v1/clinic/*`, dan **binding seluruh kontrak integrasi**.
 *
 * Semua integrasi diikat lewat kontrak, sehingga sekolah dapat mengganti salah
 * satunya (mis. memakai ICD-10 atau menagihkan layanan) hanya dengan mengubah
 * satu binding — tanpa menyentuh logika klinis maupun layar.
 */
class ClinicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/clinic.php'), 'clinic');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->bindContracts();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'clinic');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Clinic\Console\ClinicExpireFollowUpsCommand::class,
                \App\Modules\Clinic\Console\ClinicExpireBatchesCommand::class,
            ]);
        }
    }

    /**
     * Binding kontrak integrasi.
     *
     * Bila suatu saat sekolah memakai modul Finance untuk menagihkan layanan
     * kesehatan, atau ICD-10 untuk diagnosis, cukup ubah baris di sini.
     */
    protected function bindContracts(): void
    {
        $bindings = [
            StudentProviderInterface::class => EloquentStudentProvider::class,
            ParentProviderInterface::class => EloquentParentProvider::class,
            InventoryProviderInterface::class => InventoryItemProvider::class,
            NotificationProviderInterface::class => DatabaseNotificationProvider::class,
            DocumentProviderInterface::class => LocalDocumentProvider::class,
            AssetProviderInterface::class => InventoryAssetProvider::class,
            AttendanceProviderInterface::class => EloquentAttendanceProvider::class,
            BoardingProviderInterface::class => EloquentBoardingProvider::class,
            DiagnosisCodeProviderInterface::class => InternalDiagnosisCodeProvider::class,
            // Default: layanan kesehatan tidak ditagihkan (null implementation).
            FinanceProviderInterface::class => NullFinanceProvider::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    protected function registerRateLimiters(): void
    {
        $limits = (array) config('clinic.rate_limits', []);

        $limiters = [
            'clinic-module' => ['module', 300],
            'clinic-search' => ['search', 120],
            'clinic-write' => ['write', 120],
            'clinic-report' => ['report', 20],
            'clinic-download' => ['download', 60],
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
