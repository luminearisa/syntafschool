<?php

namespace App\Modules\CBT\Providers;

use App\Modules\CBT\Console\Commands\CbtExpireAttemptsCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Titik masuk modul CBT. Migrasi, rute, rate limit, dan perintah artisan
 * didaftarkan di sini supaya modul dapat dipasang tanpa menyentuh Kernel.
 */
class CbtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->registerRoutes();
        $this->registerRateLimits();
        $this->registerCommands();
    }

    protected function registerRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');
    }

    protected function registerRateLimits(): void
    {
        RateLimiter::for('cbt-start', fn (Request $request) => Limit::perMinute(8)->by('start:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('cbt-answer', fn (Request $request) => Limit::perMinute(180)->by('ans:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('cbt-heartbeat', fn (Request $request) => Limit::perMinute(40)->by('hb:'.($request->user()?->id ?? $request->ip())));
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CbtExpireAttemptsCommand::class]);
        }
    }
}
