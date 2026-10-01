<?php

namespace App\Modules\Asset\Providers;

use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Support\AssetSettingsService;
use App\Modules\Asset\Application\Support\AssetTenantResolver;
use App\Modules\Asset\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Asset\Domain\Contracts\AssetProviderInterface;
use App\Modules\Asset\Domain\Contracts\AuditProviderInterface;
use App\Modules\Asset\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Asset\Domain\Contracts\ClinicProviderInterface;
use App\Modules\Asset\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Asset\Domain\Contracts\EventProviderInterface;
use App\Modules\Asset\Domain\Contracts\FacilityProviderInterface;
use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Asset\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface;
use App\Modules\Asset\Domain\Contracts\StudentActivityProviderInterface;
use App\Modules\Asset\Domain\Contracts\VendorProviderInterface;
use App\Modules\Asset\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Asset\Infrastructure\Providers\AssetModuleProvider;
use App\Modules\Asset\Infrastructure\Providers\DatabaseNotificationProvider;
use App\Modules\Asset\Infrastructure\Providers\EloquentAcademicProvider;
use App\Modules\Asset\Infrastructure\Providers\EloquentFinanceProvider;
use App\Modules\Asset\Infrastructure\Providers\EloquentInventoryProvider;
use App\Modules\Asset\Infrastructure\Providers\EloquentProcurementProvider;
use App\Modules\Asset\Infrastructure\Providers\EloquentVendorProvider;
use App\Modules\Asset\Infrastructure\Providers\FacilityModuleProvider;
use App\Modules\Asset\Infrastructure\Providers\LocalAuditProvider;
use App\Modules\Asset\Infrastructure\Providers\LocalDocumentProvider;
use App\Modules\Asset\Infrastructure\Providers\NullAcademicProvider;
use App\Modules\Asset\Infrastructure\Providers\NullBoardingProvider;
use App\Modules\Asset\Infrastructure\Providers\NullClinicProvider;
use App\Modules\Asset\Infrastructure\Providers\NullEventProvider;
use App\Modules\Asset\Infrastructure\Providers\NullStudentActivityProvider;
use App\Modules\Asset\Infrastructure\Providers\WorkflowEngineProvider;
use App\Modules\Asset\Infrastructure\Support\IntegrationOutbox;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Asset & Sarpras.
 *
 * Mendaftarkan migrasi `asset_*`/`facilities`/`locations`, konfigurasi, batas
 * laju, perintah artisan, route `/api/v1/*`, kebijakan (Policy), dan SELURUH
 * kontrak integrasi. Semua integrasi melewati kontrak, sehingga sekolah yang
 * belum memasang modul Keuangan/Persediaan/Pengadaan tetap dapat memakai modul
 * ini — dan yang sudah memasangnya mendapat pencatatan yang sesungguhnya.
 */
class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/asset.php'), 'asset');

        $this->app->singleton(AssetTenantResolver::class);
        $this->app->singleton(AssetSettingsService::class);
        $this->app->singleton(AssetAccessService::class);
        $this->app->singleton(IntegrationOutbox::class);

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->bindContracts();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerPolicies();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Asset\Console\AssetGeneratePreventiveWorkOrdersCommand::class,
                \App\Modules\Asset\Console\AssetSendRemindersCommand::class,
                \App\Modules\Asset\Console\AssetEvaluateReplacementsCommand::class,
                \App\Modules\Asset\Console\AssetDispatchIntegrationOutboxCommand::class,
                \App\Modules\Asset\Console\AssetHealthCommand::class,
            ]);
        }
    }

    /** Seluruh kontrak integrasi modul — satu tempat untuk melihat seluruhnya. */
    protected function bindContracts(): void
    {
        $bindings = [
            // Modul ini MENYEDIAKAN (dipakai modul lain).
            AssetProviderInterface::class => AssetModuleProvider::class,
            FacilityProviderInterface::class => FacilityModuleProvider::class,

            // Modul ini MEMAKAI (integrasi ke modul lain).
            FinanceProviderInterface::class => EloquentFinanceProvider::class,
            InventoryProviderInterface::class => EloquentInventoryProvider::class,
            ProcurementProviderInterface::class => EloquentProcurementProvider::class,
            WorkflowProviderInterface::class => WorkflowEngineProvider::class,
            DocumentProviderInterface::class => LocalDocumentProvider::class,
            NotificationProviderInterface::class => DatabaseNotificationProvider::class,
            AuditProviderInterface::class => LocalAuditProvider::class,
            VendorProviderInterface::class => EloquentVendorProvider::class,

            // Akademik: pemeriksaan benturan jadwal pelajaran hanya berjalan bila
            // sekolah memetakan ruangnya. Bawaannya kosong dan dinyatakan terus
            // terang, bukan diam-diam dianggap berjalan.
            AcademicProviderInterface::class => config('asset.integration.academic_provider') === 'eloquent'
                ? EloquentAcademicProvider::class
                : NullAcademicProvider::class,

            StudentActivityProviderInterface::class => NullStudentActivityProvider::class,
            BoardingProviderInterface::class => NullBoardingProvider::class,
            ClinicProviderInterface::class => NullClinicProvider::class,
            EventProviderInterface::class => NullEventProvider::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    protected function registerPolicies(): void
    {
        $policies = [
            \App\Modules\Asset\Models\Asset::class => \App\Modules\Asset\Policies\AssetPolicy::class,
            \App\Modules\Asset\Models\Facility::class => \App\Modules\Asset\Policies\FacilityPolicy::class,
            \App\Modules\Asset\Models\FacilityBooking::class => \App\Modules\Asset\Policies\FacilityBookingPolicy::class,
            \App\Modules\Asset\Models\AssetLoan::class => \App\Modules\Asset\Policies\AssetLoanPolicy::class,
            \App\Modules\Asset\Models\AssetTransfer::class => \App\Modules\Asset\Policies\AssetTransferPolicy::class,
            \App\Modules\Asset\Models\MaintenanceRequest::class => \App\Modules\Asset\Policies\MaintenanceRequestPolicy::class,
            \App\Modules\Asset\Models\MaintenanceWorkOrder::class => \App\Modules\Asset\Policies\MaintenanceWorkOrderPolicy::class,
            \App\Modules\Asset\Models\AssetStockOpname::class => \App\Modules\Asset\Policies\AssetStockOpnamePolicy::class,
            \App\Modules\Asset\Models\AssetDisposalRequest::class => \App\Modules\Asset\Policies\AssetDisposalRequestPolicy::class,
            \App\Modules\Asset\Models\Location::class => \App\Modules\Asset\Policies\LocationPolicy::class,
            \App\Modules\Asset\Models\AssetInspection::class => \App\Modules\Asset\Policies\AssetInspectionPolicy::class,
        ];

        foreach ($policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('asset-module', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('asset-write', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        // Pemindaian label, baik saat opname maupun saat mencari aset.
        RateLimiter::for('asset-scan', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
    }

    protected function registerRoutes(): void
    {
        Route::middleware(config('asset.routes.middleware', ['api']))
            ->prefix(config('asset.routes.prefix', 'api/v1'))
            ->name('asset.')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
