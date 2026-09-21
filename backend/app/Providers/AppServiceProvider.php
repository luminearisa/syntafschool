<?php

namespace App\Providers;

use App\Models\Library;
use App\Policies\LibraryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Didaftarkan eksplisit agar tidak bergantung pada auto-discovery.
        Gate::policy(Library::class, LibraryPolicy::class);

        // Nama aksi audit modul Library memakai konvensi spesifikasi
        // (LIBRARY_CREATED, LIBRARY_LOCATION_UPDATED, …). Paket menyimpan
        // `event` sebagai nama event model mentah ("created"/"updated"), jadi
        // penamaannya disesuaikan di satu tempat ini.
        Activity::saving(function (Activity $activity): void {
            // Modul yang memakai penamaan aksi bergaya spesifikasi.
            if (! in_array($activity->log_name, ['library', 'ppdb'], true)) {
                return;
            }

            $subject = $activity->subject;

            if ($subject === null || ! method_exists($subject, 'activityEventPrefix')) {
                return;
            }

            $prefix = $subject->activityEventPrefix();
            $event = (string) $activity->event;

            // Jangan menambahkan awalan dua kali (mis. aksi agregat dari service).
            if (str_starts_with($event, $prefix)) {
                return;
            }

            $activity->event = $prefix.'_'.strtoupper($event);
        });
    }
}
