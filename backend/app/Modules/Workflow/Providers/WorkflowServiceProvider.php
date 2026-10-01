<?php

namespace App\Modules\Workflow\Providers;

use App\Modules\Workflow\Application\Services\EscalationService;
use App\Modules\Workflow\Application\Services\WorkflowInstanceService;
use App\Modules\Workflow\Domain\Contracts\ApproverResolverInterface;
use App\Modules\Workflow\Domain\Contracts\BusinessCalendarInterface;
use App\Modules\Workflow\Domain\Contracts\ConditionEvaluatorInterface;
use App\Modules\Workflow\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Workflow\Domain\Contracts\OrganizationalDirectoryInterface;
use App\Modules\Workflow\Infrastructure\Actions\DelegateBusinessAction;
use App\Modules\Workflow\Infrastructure\Actions\PublishEventAction;
use App\Modules\Workflow\Infrastructure\Actions\SendNotificationAction;
use App\Modules\Workflow\Infrastructure\Actions\UpdateSubjectStatusAction;
use App\Modules\Workflow\Infrastructure\Approvers\CostCenterApproverResolver;
use App\Modules\Workflow\Infrastructure\Approvers\DepartmentApproverResolver;
use App\Modules\Workflow\Infrastructure\Approvers\DynamicApproverResolver;
use App\Modules\Workflow\Infrastructure\Approvers\ManagerApproverResolver;
use App\Modules\Workflow\Infrastructure\Approvers\PositionApproverResolver;
use App\Modules\Workflow\Infrastructure\Approvers\RoleApproverResolver;
use App\Modules\Workflow\Infrastructure\Approvers\UserApproverResolver;
use App\Modules\Workflow\Infrastructure\Calendar\WorkingCalendar;
use App\Modules\Workflow\Infrastructure\Conditions\DefaultConditionEvaluator;
use App\Modules\Workflow\Infrastructure\Directory\DatabaseOrganizationalDirectory;
use App\Modules\Workflow\Infrastructure\Notifications\EngineNotificationProvider;
use App\Modules\Workflow\Infrastructure\Registry\WorkflowRegistrar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk Modul Workflow & Approval Engine.
 *
 * Mendaftarkan: konfigurasi, migrasi `workflow_*`, batas laju, perintah artisan,
 * route `/api/v1/workflows/*`, dan **seluruh titik ekstensi** ke
 * `WorkflowRegistrar` (resolver approver, aksi otomatis, provider konteks,
 * trigger, evaluator kondisi).
 *
 * Modul ERP lain cukup memakai `WorkflowRegistrar` dari service provider mereka
 * untuk menyumbang resolver/aksi/context provider sendiri — mereka tidak perlu
 * menyentuh berkas apa pun di modul ini, dan engine tidak perlu tahu nama modul
 * mereka.
 */
class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/workflow.php'), 'workflow');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app->singleton(WorkflowRegistrar::class);
        $this->app->singleton(WorkflowInstanceService::class);

        $this->bindContracts();
    }

    public function boot(): void
    {
        $this->registerExtensions();
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->registerCommands();
    }

    /**
     * Kontrak yang dapat ditukar sekolah tanpa mengubah engine (§74).
     */
    protected function bindContracts(): void
    {
        $this->app->bind(BusinessCalendarInterface::class, WorkingCalendar::class);
        $this->app->bind(OrganizationalDirectoryInterface::class, DatabaseOrganizationalDirectory::class);
        $this->app->bind(NotificationProviderInterface::class, config('workflow.notification_provider', EngineNotificationProvider::class));
    }

    /**
     * Daftarkan resolver, aksi, dan evaluator kondisi bawaan.
     *
     * Semuanya melewati `WorkflowRegistrar`, sehingga modul lain dapat
     * menambahkan miliknya sendiri dengan pola yang sama.
     */
    protected function registerExtensions(): void
    {
        /** @var WorkflowRegistrar $registrar */
        $registrar = $this->app->make(WorkflowRegistrar::class);

        if ($registrar->isBooted()) {
            return;
        }

        $registrar->setConditionEvaluator($this->app->make(DefaultConditionEvaluator::class));

        foreach ([
            UserApproverResolver::class,
            RoleApproverResolver::class,
            ManagerApproverResolver::class,
            DepartmentApproverResolver::class,
            CostCenterApproverResolver::class,
            PositionApproverResolver::class,
            DynamicApproverResolver::class,
        ] as $resolver) {
            $registrar->registerResolver($this->app->make($resolver));
        }

        foreach ([
            SendNotificationAction::class,
            PublishEventAction::class,
            UpdateSubjectStatusAction::class,
            DelegateBusinessAction::class,
        ] as $action) {
            $registrar->registerAction($this->app->make($action));
        }

        $registrar->markBooted();

        // Resolver/aksi yang disumbangkan modul lain lewat konfigurasi.
        foreach ((array) config('workflow.resolvers', []) as $resolverClass) {
            $registrar->registerResolver($this->app->make($resolverClass));
        }

        foreach ((array) config('workflow.actions', []) as $actionClass) {
            $registrar->registerAction($this->app->make($actionClass));
        }

        $this->app->bind(ApproverResolverInterface::class, fn ($app) => $app->make(UserApproverResolver::class));
        $this->app->bind(ConditionEvaluatorInterface::class, fn ($app) => $app->make(DefaultConditionEvaluator::class));
    }

    protected function registerRateLimiters(): void
    {
        $limits = (array) config('workflow.rate_limits', []);

        $limiters = [
            'workflow-module' => ['module', 300],
            'workflow-write' => ['write', 120],
            'workflow-decision' => ['decision', 90],
            'workflow-simulate' => ['simulate', 30],
            'workflow-analytics' => ['analytics', 30],
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

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \App\Modules\Workflow\Console\WorkflowCheckSlaCommand::class,
            \App\Modules\Workflow\Console\WorkflowExpireCommand::class,
            \App\Modules\Workflow\Console\WorkflowHealthCommand::class,
            \App\Modules\Workflow\Console\WorkflowTriggerCommand::class,
        ]);
    }
}
