<?php

namespace App\Modules\Hrd\Providers;

use App\Modules\Hrd\Application\Services\HrdAuditService;
use App\Modules\Hrd\Application\Services\HrdAccessService;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use App\Modules\Hrd\Domain\Contracts\AssetProviderInterface;
use App\Modules\Hrd\Domain\Contracts\AuditProviderInterface;
use App\Modules\Hrd\Domain\Contracts\DepartmentProviderInterface;
use App\Modules\Hrd\Domain\Contracts\DocumentStorageProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmployeeCertificationProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmployeeDocumentProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmployeeProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmployeeSkillProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmploymentProviderInterface;
use App\Modules\Hrd\Domain\Contracts\HRTransactionProviderInterface;
use App\Modules\Hrd\Domain\Contracts\HRWorkflowProviderInterface;
use App\Modules\Hrd\Domain\Contracts\HrReferenceProviderInterface;
use App\Modules\Hrd\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Hrd\Domain\Contracts\OrganizationProviderInterface;
use App\Modules\Hrd\Domain\Contracts\PositionProviderInterface;
use App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface;
use App\Modules\Hrd\Infrastructure\Lifecycle\Effects\AssignmentChangeEffect;
use App\Modules\Hrd\Infrastructure\Lifecycle\Effects\ContractChangeEffect;
use App\Modules\Hrd\Infrastructure\Lifecycle\Effects\HireEffect;
use App\Modules\Hrd\Infrastructure\Lifecycle\Effects\ManagerChangeEffect;
use App\Modules\Hrd\Infrastructure\Lifecycle\Effects\SeparationEffect;
use App\Modules\Hrd\Infrastructure\Lifecycle\Effects\StatusChangeEffect;
use App\Modules\Hrd\Infrastructure\Lifecycle\TransactionEffectRegistry;
use App\Modules\Hrd\Infrastructure\Providers\CompetencyModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\DatabaseNotificationProvider;
use App\Modules\Hrd\Infrastructure\Providers\DocumentModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\EloquentUserAccountProvider;
use App\Modules\Hrd\Infrastructure\Providers\EmployeeModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\EmploymentModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\LaravelAuditProvider;
use App\Modules\Hrd\Infrastructure\Providers\OrganizationModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\PositionModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\PrivateDocumentStorage;
use App\Modules\Hrd\Infrastructure\Providers\ReferenceModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\TransactionModuleProvider;
use App\Modules\Hrd\Infrastructure\Providers\WorkflowModuleProvider;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrTransaction;
use App\Modules\Hrd\Policies\EmployeePolicy;
use App\Modules\Hrd\Policies\TransactionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul HRD (§88).
 *
 * Yang didaftarkan di sini — dan mengapa dipisah dari controller:
 *
 *  - **Kontrak** (§2, §65): modul ini MENYEDIAKAN data pegawai/organisasi/posisi
 *    dan MEMAKAI modul lain (notifikasi, audit, penyimpanan dokumen, akun) lewat
 *    kontrak. Karena itu sekolah yang belum memasang modul Keuangan/Aset tetap
 *    dapat memakai HRD — niat integrasinya menunggu di outbox.
 *  - **Handler efek transaksi** (§33, §82): menambah jenis transaksi baru cukup
 *    dengan mendaftarkan satu kelas efek, tanpa menyentuh mesin transaksi.
 *  - **Perintah terjadwal** (§70): kontrak & sertifikasi yang berakhir, masa
 *    percobaan, dokumen, tenggat onboarding, SLA permintaan, serta penerapan
 *    transaksi yang jatuh tempo.
 *  - **Kebijakan & batas laju** (§52, §79).
 */
class HrdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/hrd.php'), 'hrd');

        $this->app->singleton(HrdSettingsService::class);
        $this->app->singleton(HrdAccessService::class);
        $this->app->singleton(IntegrationOutbox::class);
        $this->app->singleton(TransactionEffectRegistry::class);
        $this->app->singleton(HrdAuditService::class);

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->bindContracts();
        $this->registerEffects();
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerPolicies();
        $this->registerRoutes();
        $this->registerScheduler();
        $this->registerOutboxTargets();
        $this->registerWorkflowExtensions();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Hrd\Console\HrdScanExpiriesCommand::class,
                \App\Modules\Hrd\Console\HrdApplyDueTransactionsCommand::class,
                \App\Modules\Hrd\Console\HrdDispatchIntegrationOutboxCommand::class,
                \App\Modules\Hrd\Console\HrdScanDataQualityCommand::class,
            ]);
        }
    }

    /** Seluruh kontrak modul dalam satu tempat — mudah ditinjau, mudah diganti. */
    protected function bindContracts(): void
    {
        $bindings = [
            // ── Modul ini MENYEDIAKAN (dipakai modul lain) ────────────────
            EmployeeProviderInterface::class => EmployeeModuleProvider::class,
            OrganizationProviderInterface::class => OrganizationModuleProvider::class,
            DepartmentProviderInterface::class => \App\Modules\Hrd\Infrastructure\Providers\DepartmentModuleProvider::class,
            PositionProviderInterface::class => PositionModuleProvider::class,
            EmploymentProviderInterface::class => EmploymentModuleProvider::class,
            EmployeeDocumentProviderInterface::class => DocumentModuleProvider::class,
            EmployeeSkillProviderInterface::class => CompetencyModuleProvider::class,
            EmployeeCertificationProviderInterface::class => CompetencyModuleProvider::class,
            HRTransactionProviderInterface::class => TransactionModuleProvider::class,
            HrReferenceProviderInterface::class => ReferenceModuleProvider::class,
            HRWorkflowProviderInterface::class => WorkflowModuleProvider::class,

            // ── Modul ini MEMAKAI (integrasi ke modul lain) ───────────────
            NotificationProviderInterface::class => DatabaseNotificationProvider::class,
            AuditProviderInterface::class => LaravelAuditProvider::class,
            DocumentStorageProviderInterface::class => PrivateDocumentStorage::class,
            UserAccountProviderInterface::class => EloquentUserAccountProvider::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }

        // Modul yang belum dijamin terpasang diikat ke implementasi yang aman:
        // mengembalikan "belum tersedia" alih-alih melempar pengecualian, sehingga
        // HRD tetap berjalan dan niat integrasinya menunggu di outbox.
        $this->app->bind(AssetProviderInterface::class, \App\Modules\Hrd\Infrastructure\Providers\UnavailableAssetProvider::class);
        $this->app->bind(\App\Modules\Hrd\Domain\Contracts\FinanceProviderInterface::class, \App\Modules\Hrd\Infrastructure\Providers\UnavailableFinanceProvider::class);
        $this->app->bind(\App\Modules\Hrd\Domain\Contracts\AttendanceProviderInterface::class, \App\Modules\Hrd\Infrastructure\Providers\UnavailableAttendanceProvider::class);
    }

    /**
     * Handler efek transaksi (§33, §82).
     *
     * Alias di sini dirujuk `hr_transaction_types.effect_handler` — jadi sekolah
     * dapat mengarahkan "Mutasi Internal" ke handler apa pun yang terdaftar.
     */
    protected function registerEffects(): void
    {
        $this->app->afterResolving(TransactionEffectRegistry::class, function (TransactionEffectRegistry $registry): void {
            $registry->register($this->app->make(HireEffect::class));
            $registry->register($this->app->make(AssignmentChangeEffect::class));
            $registry->register($this->app->make(ManagerChangeEffect::class));
            $registry->register($this->app->make(StatusChangeEffect::class));
            $registry->register($this->app->make(SeparationEffect::class));
            $registry->register($this->app->make(ContractChangeEffect::class));
        });
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('hrd-module', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getKey() ?: $request->ip()));
        RateLimiter::for('hrd-write', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->getKey() ?: $request->ip()));
    }

    protected function registerPolicies(): void
    {
        Gate::policy(HrEmployee::class, EmployeePolicy::class);
        Gate::policy(HrTransaction::class, TransactionPolicy::class);
    }

    protected function registerRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Routes/api.php');
    }

    /** Penjadwalan modul (§70) — idempoten, aman dijalankan berkali-kali. */
    protected function registerScheduler(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Schedule::command('hrd:scan-expiries')->dailyAt('06:30')->withoutOverlapping();
        Schedule::command('hrd:apply-due-transactions')->dailyAt('00:15')->withoutOverlapping();
        Schedule::command('hrd:dispatch-outbox')->everyTenMinutes()->withoutOverlapping();
        Schedule::command('hrd:scan-data-quality')->weeklyOn(1, '05:00')->withoutOverlapping();
    }

    /**
     * Handler outbox untuk modul yang tersedia (§66).
     *
     * Modul tujuan yang belum terpasang tidak didaftarkan: baris outbox-nya tetap
     * `pending` dan terlihat pada layar kesehatan modul — bukan dianggap gagal.
     */
    protected function registerOutboxTargets(): void
    {
        $this->app->afterResolving(IntegrationOutbox::class, function (IntegrationOutbox $outbox): void {
            $outbox->register('notification', 'onboarding.started', fn (array $payload) => true);
            $outbox->register('user', 'account.provision', fn (array $payload) => $payload !== []);
            $outbox->register('user', 'account.suspend', fn (array $payload) => $payload !== []);
        });
    }

    /** Titik ekstensi Workflow Engine (§33, §66). */
    protected function registerWorkflowExtensions(): void
    {
        if (! class_exists(\App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar::class)) {
            return;
        }

        $this->app->afterResolving(\App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar::class, function ($registrar): void {
            try {
                $registrar->registerTrigger($this->app->make(\App\Modules\Hrd\Infrastructure\Workflow\HrdApprovalTrigger::class));
            } catch (\Throwable) {
                // Engine terpasang tetapi belum siap (mis. migrasinya belum jalan).
                // HRD tetap bekerja dengan persetujuan manual.
            }
        });
    }
}
