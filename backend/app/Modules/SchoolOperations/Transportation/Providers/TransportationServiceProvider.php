<?php

namespace App\Modules\SchoolOperations\Transportation\Providers;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\{
    AssetGatewayInterface,
    AttendanceGatewayInterface,
    BoardingGatewayInterface,
    ClinicGatewayInterface,
    DocumentGatewayInterface,
    EmployeeGatewayInterface,
    EventGatewayInterface,
    FacilityGatewayInterface,
    FinanceGatewayInterface,
    LocationGatewayInterface,
    MaintenanceGatewayInterface,
    NotificationGatewayInterface,
    ParentGatewayInterface,
    RouteOptimizationProviderInterface,
    StudentGatewayInterface,
    TransportationAccessProviderInterface,
    TransportationProviderInterface,
    VendorGatewayInterface
};
use App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways\DatabaseAccessControlGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mendaftarkan modul Transportation Management (School Operations):
 * migrasi `transportation_*`/`vehicle_*`/`driver_*`/`route_*`/`trip_*`,
 * kontrak integrasi (Student, Parent, Employee, Attendance, Boarding, Asset,
 * Maintenance, Finance, Notification, Facility, Event, Document, Clinic,
 * Vendor, Location), kontrak yang disediakan modul ini untuk modul lain,
 * batas laju, dan route `/api/v1/transportation/*`.
 *
 * Semua binding diambil dari `config/transportation.php` sehingga implementasi
 * dapat ditimpa per lingkungan tanpa mengubah kode modul.
 */
class TransportationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/transportation.php', 'transportation');

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
            StudentGatewayInterface::class => 'student',
            ParentGatewayInterface::class => 'parent',
            EmployeeGatewayInterface::class => 'employee',
            AttendanceGatewayInterface::class => 'attendance',
            BoardingGatewayInterface::class => 'boarding',
            AssetGatewayInterface::class => 'asset',
            MaintenanceGatewayInterface::class => 'maintenance',
            FinanceGatewayInterface::class => 'finance',
            NotificationGatewayInterface::class => 'notification',
            FacilityGatewayInterface::class => 'facility',
            EventGatewayInterface::class => 'event',
            DocumentGatewayInterface::class => 'document',
            ClinicGatewayInterface::class => 'clinic',
            LocationGatewayInterface::class => 'location',
            VendorGatewayInterface::class => 'vendor',
            RouteOptimizationProviderInterface::class => 'route_optimization',
            TransportationAccessProviderInterface::class => 'access',
            \App\Modules\SchoolOperations\Transportation\Domain\Contracts\WorkflowGatewayInterface::class => 'workflow',
        ];

        foreach ($map as $contract => $key) {
            $this->app->bind($contract, function ($app) use ($key) {
                $implementation = config('transportation.gateways.'.$key);

                return $app->make($implementation);
            });
        }

        // Kontrak yang disediakan modul ini untuk modul lain.
        $this->app->bind(
            TransportationProviderInterface::class,
            \App\Modules\SchoolOperations\Transportation\Infrastructure\Providers\TransportationModuleProvider::class
        );

        // Modul Activity mendeklarasikan kontrak transportasi untuk kegiatan luar;
        // implementasi nyata dipasang di sini (menggantikan null provider).
        $this->app->bind(
            \App\Modules\Activity\Domain\Contracts\TransportationProviderInterface::class,
            \App\Modules\SchoolOperations\Transportation\Infrastructure\Providers\ActivityTransportationAdapter::class
        );
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('transportation', function (Request $request) {
            $limit = (int) config('transportation.rate_limits.module', 180);

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('transportation-write', function (Request $request) {
            $limit = (int) config('transportation.rate_limits.write', 90);

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('transportation-driver', function (Request $request) {
            $limit = (int) config('transportation.rate_limits.driver_sync', 240);

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });
    }

    protected function registerRoutes(): void
    {
        $routes = __DIR__.'/../Routes/api.php';
        if (! file_exists($routes)) {
            return;
        }

        Route::middleware(['api', 'auth:sanctum', 'throttle:transportation'])
            ->prefix('api/v1/transportation')
            ->name('transportation.')
            ->group($routes);
    }
}
