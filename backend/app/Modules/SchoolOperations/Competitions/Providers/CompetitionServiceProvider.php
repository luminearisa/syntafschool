<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Providers;

use App\Modules\SchoolOperations\Competitions\Domain\Contracts\AchievementProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\CertificateProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\FacilityProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\FinanceProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\NotificationProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ParentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\ScoringProviderInterface;
use App\Modules\SchoolOperations\Competitions\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\ActivityAchievementProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\ActivityFacilityProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\ActivityParentProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\ActivityStudentProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\DefaultScoringProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\UnavailableCertificateProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\UnavailableFinanceProvider;
use App\Modules\SchoolOperations\Competitions\Infrastructure\Providers\UnavailableNotificationProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Entry point for the School Operations Competition domain.
 *
 * Competition owns competition-specific state only. ERP masters and adjacent
 * domains are accessed through provider contracts; migrations are registered
 * here so the module is installed by Laravel's normal migration lifecycle.
 */
class CompetitionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StudentProviderInterface::class, ActivityStudentProvider::class);
        $this->app->bind(ParentProviderInterface::class, ActivityParentProvider::class);
        $this->app->bind(AchievementProviderInterface::class, ActivityAchievementProvider::class);
        $this->app->bind(CertificateProviderInterface::class, UnavailableCertificateProvider::class);
        $this->app->bind(NotificationProviderInterface::class, UnavailableNotificationProvider::class);
        $this->app->bind(FinanceProviderInterface::class, UnavailableFinanceProvider::class);
        $this->app->bind(FacilityProviderInterface::class, ActivityFacilityProvider::class);
        $this->app->bind(ScoringProviderInterface::class, DefaultScoringProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        RateLimiter::for('competition-read', function (Request $request): Limit {
            return Limit::perMinute(180)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('competition-write', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('competition-score', function (Request $request): Limit {
            return Limit::perMinute(180)->by($request->user()?->id ?: $request->ip());
        });

        Route::middleware(['api', 'auth:sanctum', 'throttle:competition-read'])
            ->prefix('api/v1')
            ->name('competition.')
            ->group(__DIR__.'/../Routes/api.php');

        Route::middleware(['api', 'throttle:competition-read'])
            ->prefix('api/v1/public/competitions')
            ->name('competition.public.')
            ->group(__DIR__.'/../Routes/public.php');
    }
}
