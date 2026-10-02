<?php

namespace App\Modules\SchoolOperations\Religious\Providers;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{
    AssetGatewayInterface,
    AttendanceGatewayInterface,
    BoardingGatewayInterface,
    CateringGatewayInterface,
    CertificateGatewayInterface,
    DisciplineGatewayInterface,
    DocumentGatewayInterface,
    EmployeeGatewayInterface,
    EventGatewayInterface,
    FacilityGatewayInterface,
    FinanceGatewayInterface,
    InventoryGatewayInterface,
    MosqueResourceProviderInterface,
    NotificationGatewayInterface,
    ParentGatewayInterface,
    PrayerTimeProviderInterface,
    ReligiousAccessProviderInterface,
    ReligiousLifeProviderInterface,
    ReligiousPersonnelProviderInterface,
    StudentActivityGatewayInterface,
    StudentGatewayInterface,
    TahfidzGatewayInterface,
    TeacherGatewayInterface,
    TransportationGatewayInterface,
    WorkflowGatewayInterface
};
use App\Modules\SchoolOperations\Religious\Infrastructure\Providers\{
    CalculatedPrayerTimeProvider,
    CompositePrayerTimeProvider,
    ConfigPrayerTimeProvider,
    ExternalApiPrayerTimeProvider,
    ManualPrayerTimeProvider,
    MosqueResourceProvider,
    ReligiousModuleProvider,
    ReligiousPersonnelProviderImpl
};
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mendaftarkan modul Religious & Mosque Management (School Operations Phase 3).
 *
 * Yang didaftarkan:
 * - migrasi `mosque_*`, `prayer_*`, `religious_*`, `imam_*`, `muadzin_*`,
 *   `khatib_*`, `khutbah*`, `friday_*`, `ramadan_*`, `tarawih_*`, `tahajjud_*`,
 *   `iftar_*`, `suhoor_*`, `itikaf_*`, `eid_*`, `qurban_*`;
 * - kontrak integrasi (Student, Parent, Employee, Teacher, Boarding, Attendance,
 *   Tahfidz, Student Activity, Calendar, Event, Facility, Asset, Inventory,
 *   Catering, Transportation, Finance, Notification, Workflow, Document,
 *   Certificate, Discipline, Access, Prayer Time);
 * - rantai penyedia waktu shalat (Manual \u2192 Konfigurasi \u2192 Kalkulasi \u2192 API);
 * - kontrak yang DISEDIAKAN modul ini untuk modul lain;
 * - batas laju dan route `/api/v1/religious/*`.
 *
 * Semua binding diambil dari `config/religious.php` sehingga implementasi dapat
 * ditimpa per lingkungan tanpa mengubah kode modul.
 */
class ReligiousServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../../config/religious.php', 'religious');

        $this->bindGateways();
        $this->bindPrayerTimeProviders();
        $this->bindProvidedContracts();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerRateLimiters();
        $this->registerRoutes();
    }

    /** Kontrak yang DIBUTUHKAN modul ini dari modul lain. */
    protected function bindGateways(): void
    {
        $map = [
            StudentGatewayInterface::class => 'student',
            ParentGatewayInterface::class => 'parent',
            EmployeeGatewayInterface::class => 'employee',
            TeacherGatewayInterface::class => 'teacher',
            BoardingGatewayInterface::class => 'boarding',
            AttendanceGatewayInterface::class => 'attendance',
            TahfidzGatewayInterface::class => 'tahfidz',
            StudentActivityGatewayInterface::class => 'student_activity',
            \App\Modules\SchoolOperations\Religious\Domain\Contracts\CalendarGatewayInterface::class => 'calendar',
            EventGatewayInterface::class => 'event',
            FacilityGatewayInterface::class => 'facility',
            AssetGatewayInterface::class => 'asset',
            InventoryGatewayInterface::class => 'inventory',
            CateringGatewayInterface::class => 'catering',
            TransportationGatewayInterface::class => 'transportation',
            FinanceGatewayInterface::class => 'finance',
            NotificationGatewayInterface::class => 'notification',
            WorkflowGatewayInterface::class => 'workflow',
            DocumentGatewayInterface::class => 'document',
            CertificateGatewayInterface::class => 'certificate',
            DisciplineGatewayInterface::class => 'discipline',
            ReligiousAccessProviderInterface::class => 'access',
        ];

        foreach ($map as $contract => $key) {
            $this->app->bind($contract, function ($app) use ($key) {
                $implementation = (string) config('religious.gateways.'.$key);

                if ($implementation === '' || ! class_exists($implementation)) {
                    throw new \RuntimeException('Gateway `'.$key.'` belum dikonfigurasi pada config/religious.php.');
                }

                return $app->make($implementation);
            });
        }
    }

    /**
     * Rantai penyedia waktu shalat (\u00a712).
     * Penyedia gabungan mencoba setiap sumber sesuai prioritas konfigurasi,
     * sehingga sistem tidak terkunci pada satu sumber jadwal.
     */
    protected function bindPrayerTimeProviders(): void
    {
        $this->app->singleton(ManualPrayerTimeProvider::class);
        $this->app->singleton(ConfigPrayerTimeProvider::class);
        $this->app->singleton(CalculatedPrayerTimeProvider::class);
        $this->app->singleton(ExternalApiPrayerTimeProvider::class);

        $this->app->singleton(PrayerTimeProviderInterface::class, function ($app): PrayerTimeProviderInterface {
            return new CompositePrayerTimeProvider([
                $app->make(ManualPrayerTimeProvider::class),
                $app->make(ConfigPrayerTimeProvider::class),
                $app->make(CalculatedPrayerTimeProvider::class),
                $app->make(ExternalApiPrayerTimeProvider::class),
            ]);
        });
    }

    /** Kontrak yang DISEDIAKAN modul ini untuk modul lain (\u00a776). */
    protected function bindProvidedContracts(): void
    {
        $this->app->bind(ReligiousLifeProviderInterface::class, ReligiousModuleProvider::class);
        $this->app->bind(ReligiousPersonnelProviderInterface::class, ReligiousPersonnelProviderImpl::class);
        $this->app->bind(MosqueResourceProviderInterface::class, MosqueResourceProvider::class);
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('religious', function (Request $request) {
            return Limit::perMinute((int) config('religious.rate_limits.module', 180))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('religious-write', function (Request $request) {
            return Limit::perMinute((int) config('religious.rate_limits.write', 90))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Scan presensi (QR/RFID/NFC) boleh lebih sering karena perangkat lapangan.
        RateLimiter::for('religious-attendance', function (Request $request) {
            return Limit::perMinute((int) config('religious.rate_limits.attendance', 300))
                ->by($request->user()?->id ?: $request->ip());
        });
    }

    protected function registerRoutes(): void
    {
        $routes = __DIR__.'/../Routes/api.php';
        if (! file_exists($routes)) {
            return;
        }

        Route::middleware(['api', 'auth:sanctum', 'throttle:religious'])
            ->prefix('api/v1/religious')
            ->name('religious.')
            ->group(function () use ($routes): void {
                require $routes;
            });
    }
}
