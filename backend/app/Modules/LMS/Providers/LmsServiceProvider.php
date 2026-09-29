<?php

namespace App\Modules\LMS\Providers;

use App\Models\Setting;
use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Application\Support\LiveMeetingRegistry;
use App\Modules\LMS\Application\Support\QuestionTypeRegistry;
use App\Modules\LMS\Domain\Contracts\AcademicActivityFeedInterface;
use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\AcademicGradeProviderInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\LMS\Domain\Contracts\CalendarProviderInterface;
use App\Modules\LMS\Domain\Contracts\EnrollmentProviderInterface;
use App\Modules\LMS\Domain\Contracts\FileStorageProviderInterface;
use App\Modules\LMS\Domain\Contracts\FinancialEligibilityProviderInterface;
use App\Modules\LMS\Domain\Contracts\GradeProviderInterface;
use App\Modules\LMS\Domain\Contracts\InteractiveContentProviderInterface;
use App\Modules\LMS\Domain\Contracts\NotificationProviderInterface;
use App\Modules\LMS\Domain\Contracts\ParentProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Http\Controllers\LmsSettingsController;
use App\Modules\LMS\Http\Middleware\EnsureLmsPermission;
use App\Modules\LMS\Http\Middleware\ResolveLmsActor;
use App\Modules\LMS\Infrastructure\Providers\ErpAcademicActivityFeed;
use App\Modules\LMS\Infrastructure\Providers\ErpAcademicEnrollmentProvider;
use App\Modules\LMS\Infrastructure\Providers\DatabaseNotificationProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpAcademicCourseProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpClassProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpAttendanceProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpCalendarProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpFinancialEligibilityProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpParentProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpStudentProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpTeacherProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpUserProvider;
use App\Modules\LMS\Infrastructure\Providers\LocalFileStorageProvider;
use App\Modules\LMS\Infrastructure\Providers\LocalInteractiveContentProvider;
use App\Modules\LMS\Infrastructure\Providers\LinkOnlyMeetingProvider;
use App\Modules\LMS\Infrastructure\Providers\LmsGradeProvider;
use App\Modules\LMS\Infrastructure\Providers\SiakadAcademicGradeProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneAcademicActivityFeed;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneAcademicCourseProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneClassProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneStudentProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneTeacherProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneUserProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneAcademicGradeProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneAttendanceProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneCalendarProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneEnrollmentProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneFinancialEligibilityProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneParentProvider;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Observers\LmsActivityGradeObserver;
use App\Modules\LMS\Observers\LmsActivityObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Titik masuk modul LMS.
 *
 * Modul ini mandiri: konfigurasi, migrasi, route, middleware, dan binding
 * kontrak domain semuanya didaftarkan dari sini. Satu-satunya yang dibutuhkan
 * dari luar adalah `config/lms.php` (agar `LMS_STANDALONE` dapat diatur lewat
 * `.env`) dan `bootstrap/providers.php`.
 *
 * Pemilihan mode (§1, §2):
 *   LMS_STANDALONE=false → kontrak dipenuhi implementasi ERP (User, Student,
 *                          Employee, SchoolClass, Subject, AcademicYear).
 *   LMS_STANDALONE=true  → kontrak dipenuhi implementasi mandiri (`lms_users`,
 *                          `lms_learners`, `lms_guardians`).
 * Kelas Application/Http tidak berubah sama sekali saat mode berganti.
 */
class LmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('lms.php'), 'lms');

        $this->app->singleton(UserProviderInterface::class, fn () => $this->make(
            ErpUserProvider::class,
            StandaloneUserProvider::class,
        ));

        $this->app->singleton(StudentProviderInterface::class, fn ($app) => config('lms.standalone', false)
            ? new StandaloneStudentProvider
            : new ErpStudentProvider($app->make(ClassProviderInterface::class)));

        $this->app->singleton(TeacherProviderInterface::class, fn () => config('lms.standalone', false)
            ? new StandaloneTeacherProvider
            : new ErpTeacherProvider);

        $this->app->singleton(ClassProviderInterface::class, fn () => config('lms.standalone', false)
            ? new StandaloneClassProvider
            : new ErpClassProvider);

        // Sumber "course akademik" berbeda secara mendasar antar mode:
        // Integrated → `subjects`/`class_subjects` SIAKAD; Standalone → LMS sendiri.
        $this->app->singleton(AcademicCourseProviderInterface::class, fn ($app) => config('lms.standalone', false)
            ? new StandaloneAcademicCourseProvider($app->make(StudentProviderInterface::class))
            : new ErpAcademicCourseProvider($app->make(ClassProviderInterface::class)));

        $this->app->singleton(AcademicActivityFeedInterface::class, fn () => $this->make(
            ErpAcademicActivityFeed::class,
            StandaloneAcademicActivityFeed::class,
        ));

        // Phase 2 — mesin aktivitas.
        // Registry tipe soal dipakai bersama oleh penilaian kuis & lesson.
        $this->app->singleton(QuestionTypeRegistry::class);

        // Phase 4 — penyedia pertemuan daring (§17). Registry sengaja singleton
        // supaya integrasi provider (Zoom/Meet/custom) cukup didaftarkan sekali
        // di sini; tanpa pendaftaran, provider tautan-saja tetap dipakai.
        $this->app->singleton(LiveMeetingRegistry::class, function ($app) {
            $registry = new LiveMeetingRegistry;
            $registry->register(new LinkOnlyMeetingProvider);

            return $registry;
        });

        // Sumber nilai aktivitas untuk Gradebook Phase 3 (§46).
        $this->app->singleton(GradeProviderInterface::class, LmsGradeProvider::class);

        // Phase 3 — integrasi nilai ke modul akademik (§40, §63).
        // Integrated  → provider SIAKAD (menulis ke `assessments`/`scores`).
        // Standalone  → provider kosong; gradebook LMS menjadi sumber resmi.
        $this->app->singleton(AcademicGradeProviderInterface::class, fn () => config('lms.standalone', false)
            ? new StandaloneAcademicGradeProvider
            : new SiakadAcademicGradeProvider);

        /*
        |------------------------------------------------------------------
        | Phase 5 — gerbang integrasi lengkap (§4, §8, §9)
        |------------------------------------------------------------------
        |
        | Seluruh kontrak di bawah ini dipilih **sekali** di sini. Business
        | logic hanya memakai `LmsGateway`, sehingga tidak ada pemeriksaan
        | `standalone` yang tersebar di service.
        |
        | `lms.providers.*` dapat diisi kelas lain (mis. penyimpanan S3 atau
        | wali dari sistem sekolah) tanpa mengubah kode LMS.
        */
        $this->app->singleton(ParentProviderInterface::class, fn () => $this->resolveProvider(
            'parent',
            ErpParentProvider::class,
            StandaloneParentProvider::class,
        ));

        $this->app->singleton(EnrollmentProviderInterface::class, fn ($app) => $this->resolveProvider(
            'enrollment',
            ErpAcademicEnrollmentProvider::class,
            StandaloneEnrollmentProvider::class,
            [$app->make(ClassProviderInterface::class)],
        ));

        $this->app->singleton(NotificationProviderInterface::class, fn () => $this->resolveProvider(
            'notification',
            DatabaseNotificationProvider::class,
            DatabaseNotificationProvider::class,
        ));

        $this->app->singleton(CalendarProviderInterface::class, fn () => $this->resolveProvider(
            'calendar',
            ErpCalendarProvider::class,
            StandaloneCalendarProvider::class,
        ));

        $this->app->singleton(FileStorageProviderInterface::class, fn () => $this->resolveProvider(
            'files',
            LocalFileStorageProvider::class,
            LocalFileStorageProvider::class,
        ));

        $this->app->singleton(AttendanceProviderInterface::class, fn () => $this->resolveProvider(
            'attendance',
            ErpAttendanceProvider::class,
            StandaloneAttendanceProvider::class,
        ));

        $this->app->singleton(FinancialEligibilityProviderInterface::class, fn () => $this->resolveProvider(
            'finance',
            ErpFinancialEligibilityProvider::class,
            StandaloneFinancialEligibilityProvider::class,
        ));

        $this->app->singleton(InteractiveContentProviderInterface::class, fn () => $this->resolveProvider(
            'interactive_content',
            LocalInteractiveContentProvider::class,
            LocalInteractiveContentProvider::class,
        ));

        // Gerbang tunggal: seluruh service berbicara ke sini, bukan ke provider.
        $this->app->singleton(LmsGateway::class);
    }

    /**
     * Pilih implementasi kontrak: pengaturan eksplisit menang, lalu mode.
     *
     * @param  class-string  $integrated
     * @param  class-string  $standalone
     * @param  list<object>  $dependencies
     */
    private function resolveProvider(string $key, string $integrated, string $standalone, array $dependencies = []): object
    {
        $configured = config('lms.providers.'.$key);
        $class = is_string($configured) && $configured !== ''
            ? $configured
            : (config('lms.standalone', false) ? $standalone : $integrated);

        if (! class_exists($class)) {
            $class = config('lms.standalone', false) ? $standalone : $integrated;
        }

        return $dependencies === [] ? new $class : new $class(...$dependencies);
    }

    public function boot(): void
    {
        $this->applyStoredSettings();

        // Migrasi modul berada di dalam modul agar dapat dipindahkan utuh.
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerGradebookObservers();
        $this->registerCommands();
        $this->registerRateLimiters();
    }

    /**
     * Rate limit LMS (§30).
     *
     * Batasnya dapat diubah sekolah lewat `config/lms.php` tanpa deploy. Setiap
     * limiter dibedakan per pengguna (bukan per IP) bila pengguna dikenal, supaya
     * satu jaringan sekolah (NAT) tidak saling menghabiskan kuota.
     */
    private function registerRateLimiters(): void
    {
        $limits = (array) config('lms.rate_limits', []);

        $limiters = [
            'lms-certificate-verify' => ['certificate_verify', 30],
            'lms-live-join' => ['live_join', 30],
            'lms-interactive-respond' => ['quiz_answer', 120],
            'lms-message' => ['notification', 60],
            'lms-course-import' => ['course_import', 10],
            'lms-file-upload' => ['file_upload', 30],
            'lms-scorm-tracking' => ['scorm_tracking', 300],
            'lms-report-export' => ['report_export', 20],
            'lms-grade-sync' => ['grade_sync', 60],
            'lms-public-api' => ['public_api', 120],
            'lms-quiz-attempt' => ['quiz_attempt', 10],
            'lms-quiz-answer' => ['quiz_answer', 120],
            'lms-submission' => ['submission', 20],
            'lms-forum-post' => ['forum_post', 30],
            'lms-search' => ['search', 60],
        ];

        foreach ($limiters as $name => [$key, $default]) {
            $perMinute = max(1, (int) ($limits[$key] ?? $default));

            RateLimiter::for($name, function (Request $request) use ($perMinute): Limit {
                $identifier = $request->user()?->getAuthIdentifier();

                return Limit::perMinute($perMinute)->by($identifier !== null ? 'user:'.$identifier : 'ip:'.$request->ip());
            });
        }
    }

    /**
     * Perintah artisan modul (§11).
     *
     * Didaftarkan dari sini (bukan Kernel) supaya modul tetap dapat dipindahkan
     * utuh tanpa menyentuh berkas inti aplikasi.
     */
    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \App\Modules\LMS\Console\Commands\LmsHealthCommand::class,
            \App\Modules\LMS\Console\Commands\LmsIntegrityCommand::class,
            \App\Modules\LMS\Console\Commands\LmsGradeSyncCommand::class,
            \App\Modules\LMS\Console\Commands\LmsReminderCommand::class,
            \App\Modules\LMS\Console\Commands\LmsAutomationCommand::class,
            \App\Modules\LMS\Console\Commands\LmsPruneCommand::class,
            \App\Modules\LMS\Console\Commands\LmsReportCommand::class,
        ]);
    }

    public function provides(): array
    {
        return [
            UserProviderInterface::class,
            StudentProviderInterface::class,
            TeacherProviderInterface::class,
            ClassProviderInterface::class,
            AcademicCourseProviderInterface::class,
            AcademicActivityFeedInterface::class,
            GradeProviderInterface::class,
            AcademicGradeProviderInterface::class,
            QuestionTypeRegistry::class,
            LiveMeetingRegistry::class,
        ];
    }

    /**
     * Instansiasi implementasi kontrak sesuai mode yang aktif.
     *
     * @param  class-string  $integrated
     * @param  class-string  $standalone
     * @param  list<mixed>  $dependencies
     */
    protected function make(string $integrated, string $standalone, array $dependencies = []): object
    {
        $class = config('lms.standalone', false) ? $standalone : $integrated;

        return new $class(...$dependencies);
    }

    /**
     * Hubungkan aktivitas & nilai aktivitas ke Gradebook secara otomatis (§64).
     *
     * Tujuannya satu: tidak ada aktivitas bernilai yang luput dari gradebook.
     * Observer sengaja "lembut" — bila gradebook sedang dibekukan, penilaian
     * aktivitas tetap berhasil dan sinkronisasinya ditunda (dapat dijalankan
     * manual dari halaman gradebook).
     */
    protected function registerGradebookObservers(): void
    {
        LmsActivity::observe(LmsActivityObserver::class);
        LmsActivityGrade::observe(LmsActivityGradeObserver::class);
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('lms.actor', ResolveLmsActor::class);
        $router->aliasMiddleware('lms.permission', EnsureLmsPermission::class);
    }

    protected function registerRoutes(): void
    {
        // Prefix `api/v1` ditulis lengkap (bukan hanya `v1`) karena route modul
        // didaftarkan langsung pada router: `apiPrefix` di bootstrap/app.php
        // hanya berlaku untuk berkas route bawaan aplikasi.
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Routes/api.php');
    }

    /**
     * Terapkan pengaturan yang disimpan admin (§35 `lms.settings.manage`).
     *
     * Nilai tersimpan menang atas `.env` supaya sekolah dapat mengubah perilaku
     * LMS tanpa deploy. Aman dipanggil sebelum migrasi: tabel yang belum ada
     * cukup dilewati.
     */
    protected function applyStoredSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $stored = Setting::query()->where('key', LmsSettingsController::SETTINGS_KEY)->value('value');

            if (! is_string($stored) || $stored === '') {
                return;
            }

            $settings = json_decode($stored, true);

            if (! is_array($settings)) {
                return;
            }

            $map = [
                'self_enrollment' => 'lms.enrollment.self_enrollment',
                'suspend_on_academic_cancel' => 'lms.enrollment.suspend_on_academic_cancel',
                'guest_preview' => 'lms.guest.preview_items',
                'parent_view_grades' => 'lms.parent.can_view_grades',
                'parent_view_feedback' => 'lms.parent.can_view_feedback',
                'upload_max_kb' => 'lms.uploads.max_size_kb',

                // Phase 4 — sakelar modul opsional (§1, §13, §21, §28, §31, §58).
                'enforce_sequential' => 'lms.learning_path.enforce_sequential',
                'drip_enabled' => 'lms.drip.enabled',
                'live_default_provider' => 'lms.live.default_provider',
                'live_join_window_minutes' => 'lms.live.join_window_minutes',
                'live_join_grace_minutes' => 'lms.live.join_grace_minutes',
                'live_recording_threshold' => 'lms.live.recording_completion_threshold',
                'certificate_enabled' => 'lms.certificate.enabled',
                'certificate_passing_grade' => 'lms.certificate.passing_grade',
                'gamification_enabled' => 'lms.gamification.enabled',
                'leaderboard_enabled' => 'lms.gamification.leaderboard_enabled',
                'leaderboard_display' => 'lms.gamification.display',
                'streak_enabled' => 'lms.gamification.streak.enabled',
                'review_enabled' => 'lms.reviews.enabled',
                'review_auto_approve' => 'lms.reviews.auto_approve',
                'review_display_name' => 'lms.reviews.public_display_name',
                'engagement_enabled' => 'lms.engagement.enabled',
                'health_inactive_days' => 'lms.engagement.health.inactive_days',
                'health_low_completion_percent' => 'lms.engagement.health.low_completion_percent',
            ];

            foreach ($map as $key => $configKey) {
                if (array_key_exists($key, $settings)) {
                    config([$configKey => $settings[$key]]);
                }
            }
        } catch (Throwable) {
            // Kegagalan membaca pengaturan tidak boleh menjatuhkan aplikasi
            // (mis. saat `php artisan migrate` sebelum tabel `settings` ada).
        }
    }
}
