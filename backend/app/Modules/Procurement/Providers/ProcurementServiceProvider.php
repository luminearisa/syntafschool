<?php

namespace App\Modules\Procurement\Providers;

use App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface as AssetProcurementContract;
use App\Modules\Asset\Domain\Contracts\VendorProviderInterface as AssetVendorContract;
use App\Modules\Procurement\Domain\Contracts\AccountingProviderInterface;
use App\Modules\Procurement\Domain\Contracts\AssetProviderInterface;
use App\Modules\Procurement\Domain\Contracts\BudgetProviderInterface;
use App\Modules\Procurement\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Procurement\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Procurement\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Procurement\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Procurement\Infrastructure\Providers\EloquentProcurementProvider;
use App\Modules\Procurement\Infrastructure\Providers\EloquentVendorProvider;
use App\Modules\Procurement\Infrastructure\Providers\LocalDocumentProvider;
use App\Modules\Procurement\Infrastructure\Providers\LogNotificationProvider;
use App\Modules\Procurement\Infrastructure\Providers\NullAccountingProvider;
use App\Modules\Procurement\Infrastructure\Providers\NullAssetProvider;
use App\Modules\Procurement\Infrastructure\Providers\NullBudgetProvider;
use App\Modules\Procurement\Infrastructure\Providers\NullInventoryProvider;
use App\Modules\Procurement\Infrastructure\Providers\NullWorkflowProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Procurement & Vendor.
 *
 * Mendaftarkan migrasi `proc_*`, konfigurasi, batas laju, perintah artisan,
 * route `/api/v1/procurement/*`, dan **seluruh binding kontrak integrasi**.
 *
 * Dua arah integrasi yang diikat di sini:
 *  1. **Keluar** — kontrak milik modul ini (Accounting, Budget, Inventory, Asset,
 *     Workflow, Notification, Document). Default `Null*` menjaga modul tetap
 *     berfungsi walau modul tujuan belum terpasang; ganti satu baris bila
 *     modul tersebut tersedia.
 *  2. **Masuk** — kontrak milik modul Asset (`ProcurementProviderInterface`,
 *     `VendorProviderInterface`) yang dipenuhi Procurement sebagai sumber
 *     kebenaran pengadaan & data rekanan.
 */
class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/procurement.php'), 'procurement');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->bindContracts();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Procurement\Console\ComputeVendorPerformanceCommand::class,
                \App\Modules\Procurement\Console\RetryAccountingHandoffCommand::class,
            ]);
        }
    }

    /** Binding kontrak integrasi (dua arah). */
    protected function bindContracts(): void
    {
        // Keluar — apa yang Procurement butuhkan dari modul lain.
        $outbound = [
            AccountingProviderInterface::class => NullAccountingProvider::class,
            BudgetProviderInterface::class => NullBudgetProvider::class,
            InventoryProviderInterface::class => NullInventoryProvider::class,
            AssetProviderInterface::class => NullAssetProvider::class,
            WorkflowProviderInterface::class => NullWorkflowProvider::class,
            NotificationProviderInterface::class => LogNotificationProvider::class,
            DocumentProviderInterface::class => LocalDocumentProvider::class,
        ];

        foreach ($outbound as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }

        // Masuk — apa yang Procurement sediakan untuk modul lain (Asset).
        $this->app->bind(AssetVendorContract::class, EloquentVendorProvider::class);
        $this->app->bind(AssetProcurementContract::class, EloquentProcurementProvider::class);
    }

    protected function registerRateLimiters(): void
    {
        $limits = (array) config('procurement.rate_limits', []);

        $limiters = [
            'procurement-module' => ['module', 300],
            'procurement-search' => ['search', 120],
            'procurement-write' => ['write', 120],
            'procurement-report' => ['report', 20],
            'procurement-download' => ['download', 60],
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
    }
}
