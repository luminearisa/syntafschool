<?php

namespace App\Providers;

use App\Listeners\RecordScheduledTaskFailure;
use App\Models\Library;
use App\Models\User;
use App\Policies\LibraryPolicy;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Event;
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
        // Super-admin selalu lolos pemeriksaan izin apa pun (pola standar
        // spatie). Tanpa ini, peran `super-admin` hanya memegang izin yang
        // *kebetulan* sudah ada saat RolePermissionSeeder berjalan, sehingga
        // setiap modul yang izinnya di-seed belakangan (atau lupa didaftarkan
        // di DatabaseSeeder) menolak super-admin dengan 403 — persis yang
        // terjadi pada modul Religious/Masjid. Peran lain tetap mengikuti
        // izin & policy yang berlaku; bypass ini hanya untuk super-admin.
        Gate::before(function (?User $user): ?bool {
            return $user?->hasRole('super-admin') ? true : null;
        });

        // Didaftarkan eksplisit agar tidak bergantung pada auto-discovery.
        Gate::policy(Library::class, LibraryPolicy::class);

        // Jadwal Keuangan yang gagal masuk register operasi (§29). Sama seperti
        // policy di atas: terdaftar di sini, bukan mengandalkan discovery, supaya
        // "kenapa kegagalan scheduler tidak tercatat" punya satu jawaban di kode.
        Event::listen(ScheduledTaskFailed::class, RecordScheduledTaskFailure::class);

        // Nama aksi audit modul Library memakai konvensi spesifikasi
        // (LIBRARY_CREATED, LIBRARY_LOCATION_UPDATED, …). Paket menyimpan
        // `event` sebagai nama event model mentah ("created"/"updated"), jadi
        // penamaannya disesuaikan di satu tempat ini.
        Activity::saving(function (Activity $activity): void {
            // Modul yang memakai penamaan aksi bergaya spesifikasi.
            if (! in_array($activity->log_name, ['library', 'ppdb', 'wallet', 'boarding', 'finance', 'lms', 'cbt', 'clinic'], true)) {
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
