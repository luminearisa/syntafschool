<?php

namespace App\Modules\Hrd\Providers;

use App\Modules\Hrd\Benefits\Services\BenefitService;
use App\Modules\Hrd\Career\Services\CareerService;
use App\Modules\Hrd\Console\AccrueLeaveCommand;
use App\Modules\Hrd\Console\MarkOverdueTrainingCommand;
use App\Modules\Hrd\Console\ScanAlertsCommand;
use App\Modules\Hrd\Console\SnapshotAnalyticsCommand;
use App\Modules\Hrd\Core\Domain\Contracts\AIHRProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\BenefitsProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\CareerProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\CompetencyProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\FinancePostingProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\HelpdeskProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\LearningDeliveryProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\LearningProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\LeaveProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\PayrollProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\PayrollTaxRuleProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\PerformanceProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\RecruitmentProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\SurveyProviderInterface;
use App\Modules\Hrd\Core\Domain\Contracts\TalentProviderInterface;
use App\Modules\Hrd\Core\Infrastructure\NullAIHRProvider;
use App\Modules\Hrd\Core\Infrastructure\NullFinancePostingProvider;
use App\Modules\Hrd\Core\Infrastructure\NullHelpdeskProvider;
use App\Modules\Hrd\Core\Infrastructure\NullLearningDeliveryProvider;
use App\Modules\Hrd\Core\Infrastructure\NullSurveyProvider;
use App\Modules\Hrd\Core\Services\AlertService;
use App\Modules\Hrd\Core\Services\EligibilityEngine;
use App\Modules\Hrd\Core\Services\RuleEngine;
use App\Modules\Hrd\Learning\Services\LearningService;
use App\Modules\Hrd\Leave\Services\LeaveRequestService;
use App\Modules\Hrd\Payroll\Services\PayrollService;
use App\Modules\Hrd\Payroll\Services\TaxCalculationService;
use App\Modules\Hrd\Performance\Providers\CompetencyProvider;
use App\Modules\Hrd\Performance\Services\PerformanceService;
use App\Modules\Hrd\Recruitment\Providers\RecruitmentProvider;
use App\Modules\Hrd\Talent\Services\TalentService;
use App\Modules\Hrd\Workforce\Attendance\AttendanceProviderRegistry;
use App\Modules\Hrd\Workforce\Services\AttendanceService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Modul HRD Phase 2.
 *
 * Mendaftarkan seluruh kontrak domain, penyedia luar (LMS, Helpdesk, Survey,
 * Finance, AI) beserta implementasi bawaan "belum terpasang", rute API, dan
 * perintah otomasi terjadwal. Migrasi Phase 2 berada pada folder migrasi modul
 * HRD yang sama sehingga urutannya otomatis mengikuti Phase 1.
 */
class HrdPhase2ServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        RecruitmentProviderInterface::class => RecruitmentProvider::class,
        AttendanceProviderInterface::class => AttendanceService::class,
        LeaveProviderInterface::class => LeaveRequestService::class,
        PayrollProviderInterface::class => PayrollService::class,
        BenefitsProviderInterface::class => BenefitService::class,
        PerformanceProviderInterface::class => PerformanceService::class,
        LearningProviderInterface::class => LearningService::class,
        CompetencyProviderInterface::class => CompetencyProvider::class,
        CareerProviderInterface::class => CareerService::class,
        TalentProviderInterface::class => TalentService::class,

        // Titik integrasi ke luar: implementasi bawaan aman dipakai sebelum
        // modul tujuan terpasang, dan diganti lewat binding saat tersedia.
        LearningDeliveryProviderInterface::class => NullLearningDeliveryProvider::class,
        HelpdeskProviderInterface::class => NullHelpdeskProvider::class,
        SurveyProviderInterface::class => NullSurveyProvider::class,
        FinancePostingProviderInterface::class => NullFinancePostingProvider::class,
        AIHRProviderInterface::class => NullAIHRProvider::class,

        // Aturan pajak sungguhan dari tabel berversi — bukan placeholder.
        PayrollTaxRuleProviderInterface::class => TaxCalculationService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(RuleEngine::class);
        $this->app->singleton(EligibilityEngine::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(AttendanceProviderRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/phase2.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AccrueLeaveCommand::class,
                ScanAlertsCommand::class,
                SnapshotAnalyticsCommand::class,
                MarkOverdueTrainingCommand::class,
            ]);
        }

        RateLimiter::for('hrd-phase2', function ($request) {
            $key = $request->user()?->getKey();

            return Limit::perMinute($key !== null ? 120 : 30)->by($key ?? $request->ip());
        });
    }
}
