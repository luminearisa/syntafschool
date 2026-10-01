<?php

namespace App\Modules\Bk\Providers;

use App\Modules\Bk\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Bk\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Bk\Domain\Contracts\AuditProviderInterface;
use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Bk\Domain\Contracts\ClinicSupportProviderInterface;
use App\Modules\Bk\Domain\Contracts\DisciplineProviderInterface;
use App\Modules\Bk\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Bk\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Bk\Domain\Contracts\ParentProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentActivityProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentProviderInterface;
use App\Modules\Bk\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Bk\Infrastructure\Providers\ActivitySignalProvider;
use App\Modules\Bk\Infrastructure\Providers\ClinicSummaryProvider;
use App\Modules\Bk\Infrastructure\Providers\DatabaseNotificationProvider;
use App\Modules\Bk\Infrastructure\Providers\EloquentAcademicProvider;
use App\Modules\Bk\Infrastructure\Providers\EloquentAttendanceProvider;
use App\Modules\Bk\Infrastructure\Providers\EloquentBoardingProvider;
use App\Modules\Bk\Infrastructure\Providers\EloquentDisciplineProvider;
use App\Modules\Bk\Infrastructure\Providers\EloquentParentProvider;
use App\Modules\Bk\Infrastructure\Providers\EloquentStudentProvider;
use App\Modules\Bk\Infrastructure\Providers\LocalDocumentProvider;
use App\Modules\Bk\Infrastructure\Providers\NullAuditProvider;
use App\Modules\Bk\Infrastructure\Providers\WorkflowEngineProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Bimbingan & Konseling.
 *
 * Mendaftarkan migrasi `bk_*`, konfigurasi, batas laju, perintah artisan, route
 * `/api/v1/bk/*`, **kebijakan (Policy)**, dan **seluruh kontrak integrasi**.
 *
 * Semua integrasi melewati kontrak, sehingga sekolah dapat mengganti sumber data
 * (mis. memakai penyedia dokumen berbeda) hanya dengan mengubah satu binding —
 * tanpa menyentuh alur kasus maupun kerahasiaannya.
 */
class BkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/bk.php'), 'bk');

        // Singleton: keadaan unit yang sedang aktif harus sama di seluruh
        // permintaan, termasuk saat global scope model bertanya.
        $this->app->singleton(\App\Modules\Bk\Application\Support\BkTenantResolver::class);
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
                \App\Modules\Bk\Console\BkFlagOverdueFollowUpsCommand::class,
                \App\Modules\Bk\Console\BkSendAppointmentRemindersCommand::class,
                \App\Modules\Bk\Console\BkExpireConsentsCommand::class,
                \App\Modules\Bk\Console\BkReviewOverdueCasesCommand::class,
                \App\Modules\Bk\Console\BkHealthCommand::class,
                \App\Modules\Bk\Console\BkRunEarlyWarningCommand::class,
                \App\Modules\Bk\Console\BkMonitorSlaCommand::class,
                \App\Modules\Bk\Console\BkRunAutomationCommand::class,
                \App\Modules\Bk\Console\BkApplyRetentionCommand::class,
            ]);
        }
    }

    protected function bindContracts(): void
    {
        $bindings = [
            StudentProviderInterface::class => EloquentStudentProvider::class,
            ParentProviderInterface::class => EloquentParentProvider::class,
            AcademicProviderInterface::class => EloquentAcademicProvider::class,
            AttendanceProviderInterface::class => EloquentAttendanceProvider::class,
            DisciplineProviderInterface::class => EloquentDisciplineProvider::class,
            BoardingProviderInterface::class => EloquentBoardingProvider::class,
            ClinicSupportProviderInterface::class => ClinicSummaryProvider::class,
            StudentActivityProviderInterface::class => ActivitySignalProvider::class,
            NotificationProviderInterface::class => DatabaseNotificationProvider::class,
            DocumentProviderInterface::class => LocalDocumentProvider::class,
            WorkflowProviderInterface::class => WorkflowEngineProvider::class,
            AuditProviderInterface::class => NullAuditProvider::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    /** Policy didaftarkan eksplisit agar tidak bergantung pada penemuan otomatis. */
    protected function registerPolicies(): void
    {
        Gate::policy(\App\Modules\Bk\Models\BkCase::class, \App\Modules\Bk\Policies\BkCasePolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkReferral::class, \App\Modules\Bk\Policies\BkReferralPolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkCounselingSession::class, \App\Modules\Bk\Policies\BkCounselingSessionPolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkAssessment::class, \App\Modules\Bk\Policies\BkAssessmentPolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkAppointment::class, \App\Modules\Bk\Policies\BkAppointmentPolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkCaseNote::class, \App\Modules\Bk\Policies\BkCaseNotePolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkCaseConference::class, \App\Modules\Bk\Policies\BkCaseConferencePolicy::class);
        Gate::policy(\App\Modules\Bk\Models\BkFollowUp::class, \App\Modules\Bk\Policies\BkFollowUpPolicy::class);
    }

    protected function registerRateLimiters(): void
    {
        $limits = (array) config('bk.rate_limits', []);

        $limiters = [
            'bk-module' => ['module', 300],
            'bk-search' => ['search', 120],
            'bk-write' => ['write', 120],
            'bk-report' => ['report', 20],
            'bk-download' => ['download', 60],
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
