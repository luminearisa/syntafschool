<?php

namespace App\Modules\Activity\Providers;

use App\Modules\Activity\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Activity\Domain\Contracts\AssetProviderInterface;
use App\Modules\Activity\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Activity\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Activity\Domain\Contracts\CertificateProviderInterface;
use App\Modules\Activity\Domain\Contracts\CounselingProviderInterface;
use App\Modules\Activity\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Activity\Domain\Contracts\EventProviderInterface;
use App\Modules\Activity\Domain\Contracts\FacilityProviderInterface;
use App\Modules\Activity\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Activity\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Activity\Domain\Contracts\LmsProviderInterface;
use App\Modules\Activity\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Activity\Domain\Contracts\ParentProviderInterface;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Domain\Contracts\TeacherProviderInterface;
use App\Modules\Activity\Domain\Contracts\TransportationProviderInterface;
use App\Modules\Activity\Infrastructure\Providers\ConfigurableFinanceProvider;
use App\Modules\Activity\Infrastructure\Providers\DatabaseNotificationProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentAttendanceProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentBoardingProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentFacilityProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentLmsProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentParentProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentStudentProvider;
use App\Modules\Activity\Infrastructure\Providers\EloquentTeacherProvider;
use App\Modules\Activity\Infrastructure\Providers\InventoryAssetProvider;
use App\Modules\Activity\Infrastructure\Providers\InventoryItemProvider;
use App\Modules\Activity\Infrastructure\Providers\LocalAcademicProvider;
use App\Modules\Activity\Infrastructure\Providers\LocalCertificateProvider;
use App\Modules\Activity\Infrastructure\Providers\LocalDocumentProvider;
use App\Modules\Activity\Infrastructure\Providers\NullCounselingProvider;
use App\Modules\Activity\Infrastructure\Providers\NullEventProvider;
use App\Modules\Activity\Infrastructure\Providers\NullTransportationProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Student Activity & Extracurricular.
 *
 * Mendaftarkan migrasi `activity_*`, konfigurasi, batas laju, perintah artisan,
 * route `/api/v1/activities/*`, dan **seluruh kontrak integrasi**.
 *
 * Modul ini dirancang dapat berjalan di dua dunia:
 *  - **Integrated ERP** (keadaan sekarang): siswa, orang tua, pegawai, fasilitas,
 *    inventaris, LMS, dan kehadiran dibaca/ditulis lewat kontrak — masing-masing
 *    modul tetap menjadi sumber kebenarannya,
 *  - **Standalone Activity Module**: seluruh binding cukup diganti ke penerapan
 *    mandiri (mis. `NullEventProvider`) tanpa menyentuh layanan atau layar.
 */
class ActivityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/activity.php'), 'activity');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->bindContracts();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'activity');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Activity\Console\ActivityGenerateSessionsCommand::class,
                \App\Modules\Activity\Console\ActivitySessionReminderCommand::class,
                \App\Modules\Activity\Console\ActivityExpireConsentsCommand::class,
                \App\Modules\Activity\Console\ActivityEvaluateCompletionCommand::class,
                \App\Modules\Activity\Console\ActivityForgetDetectionCommand::class,
                \App\Modules\Activity\Console\ActivityHealthCommand::class,
            ]);
        }
    }

    /**
     * Binding kontrak integrasi.
     *
     * Inilah satu-satunya tempat yang perlu diubah bila sekolah memakai modul
     * lain (mis. Event Management sungguhan, ICD kurikulum lain, atau tarif
     * kegiatan langsung ke Finance).
     */
    protected function bindContracts(): void
    {
        $bindings = [
            StudentProviderInterface::class => EloquentStudentProvider::class,
            ParentProviderInterface::class => EloquentParentProvider::class,
            TeacherProviderInterface::class => EloquentTeacherProvider::class,
            AttendanceProviderInterface::class => EloquentAttendanceProvider::class,
            AcademicProviderInterface::class => LocalAcademicProvider::class,
            LmsProviderInterface::class => EloquentLmsProvider::class,
            FinanceProviderInterface::class => ConfigurableFinanceProvider::class,
            InventoryProviderInterface::class => InventoryItemProvider::class,
            AssetProviderInterface::class => InventoryAssetProvider::class,
            FacilityProviderInterface::class => EloquentFacilityProvider::class,
            EventProviderInterface::class => NullEventProvider::class,
            TransportationProviderInterface::class => NullTransportationProvider::class,
            NotificationProviderInterface::class => DatabaseNotificationProvider::class,
            CertificateProviderInterface::class => LocalCertificateProvider::class,
            DocumentProviderInterface::class => LocalDocumentProvider::class,
            BoardingProviderInterface::class => EloquentBoardingProvider::class,
            CounselingProviderInterface::class => NullCounselingProvider::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->app->bind($contract, fn ($app) => $app->make($implementation));
        }
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('activity-module', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('activity-write', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('activity-public-verify', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });
    }

    protected function registerRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/activities')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
