<?php

namespace App\Modules\SchoolOperations\Laundry\Providers;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\{
    AssetProviderInterface,
    BoardingProviderInterface,
    DocumentProviderInterface,
    FinanceProviderInterface,
    InventoryProviderInterface,
    NotificationProviderInterface,
    ParentProviderInterface,
    RfidProviderInterface,
    StudentProviderInterface,
    WalletProviderInterface,
    WorkflowProviderInterface
};
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mendaftarkan modul Laundry Management (School Operations):
 * migrasi `laundry_*`, kontrak integrasi (Student, Parent, Boarding, Wallet,
 * RFID, Finance, Notification, Asset, Inventory, Workflow, Document), batas
 * laju, dan route `/api/v1/laundry/*` + portal `/me/laundry` &
 * `/parent/children/{id}/laundry`.
 *
 * Semua binding diambil dari `config/laundry.php` sehingga implementasi dapat
 * ditimpa per lingkungan tanpa mengubah kode modul.
 */
class LaundryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/laundry.php', 'laundry');

        $this->bindGateways();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerRateLimiters();
        $this->registerRoutes();
    }

    protected function bindGateways(): void
    {
        $map = [
            StudentProviderInterface::class => 'student',
            ParentProviderInterface::class => 'parent',
            BoardingProviderInterface::class => 'boarding',
            WalletProviderInterface::class => 'wallet',
            RfidProviderInterface::class => 'rfid',
            FinanceProviderInterface::class => 'finance',
            NotificationProviderInterface::class => 'notification',
            AssetProviderInterface::class => 'asset',
            InventoryProviderInterface::class => 'inventory',
            WorkflowProviderInterface::class => 'workflow',
            DocumentProviderInterface::class => 'document',
        ];

        foreach ($map as $contract => $key) {
            $this->app->bind($contract, function ($app) use ($key) {
                return $app->make(config('laundry.gateways.'.$key));
            });
        }
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('laundry', function (Request $request) {
            return Limit::perMinute((int) config('laundry.rate_limits.module', 180))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('laundry-write', function (Request $request) {
            return Limit::perMinute((int) config('laundry.rate_limits.write', 90))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Pembayaran dibatasi lebih ketat — lapisan tambahan di atas idempotensi.
        RateLimiter::for('laundry-payment', function (Request $request) {
            return Limit::perMinute((int) config('laundry.rate_limits.payment', 30))
                ->by($request->user()?->id ?: $request->ip());
        });
    }

    protected function registerRoutes(): void
    {
        $routes = __DIR__.'/../Routes';

        Route::middleware(['api', 'auth:sanctum', 'throttle:laundry'])
            ->prefix('api/v1/laundry')
            ->name('laundry.')
            ->group($routes.'/api.php');

        // §62: portal santri — identitas dari akun, bukan parameter.
        Route::middleware(['api', 'auth:sanctum', 'throttle:laundry'])
            ->prefix('api/v1/me/laundry')
            ->name('laundry.me.')
            ->group($routes.'/me.php');

        // §63: portal wali — anak diverifikasi sebagai dependent sah.
        Route::middleware(['api', 'auth:sanctum', 'throttle:laundry'])
            ->prefix('api/v1/parent')
            ->name('laundry.parent.')
            ->group($routes.'/parent.php');
    }
}
