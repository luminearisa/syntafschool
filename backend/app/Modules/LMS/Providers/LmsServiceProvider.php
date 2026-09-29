<?php

namespace App\Modules\LMS\Providers;

use App\Models\Setting;
use App\Modules\LMS\Domain\Contracts\AcademicActivityFeedInterface;
use App\Modules\LMS\Domain\Contracts\AcademicGradeProviderInterface;
use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Application\Support\QuestionTypeRegistry;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\GradeProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Http\Controllers\LmsSettingsController;
use App\Modules\LMS\Http\Middleware\EnsureLmsPermission;
use App\Modules\LMS\Observers\LmsActivityGradeObserver;
use App\Modules\LMS\Observers\LmsActivityObserver;
use App\Modules\LMS\Http\Middleware\ResolveLmsActor;
use App\Modules\LMS\Infrastructure\Providers\ErpAcademicActivityFeed;
use App\Modules\LMS\Infrastructure\Providers\ErpAcademicCourseProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpClassProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpStudentProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpTeacherProvider;
use App\Modules\LMS\Infrastructure\Providers\ErpUserProvider;
use App\Modules\LMS\Infrastructure\Providers\LmsGradeProvider;
use App\Modules\LMS\Infrastructure\Providers\SiakadAcademicGradeProvider;
use App\Modules\LMS\Infrastructure\Providers\StandaloneAcademicGradeProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneAcademicActivityFeed;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneAcademicCourseProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneClassProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneStudentProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneTeacherProvider;
use App\Modules\LMS\Infrastructure\Providers\Standalone\StandaloneUserProvider;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
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

        // Sumber nilai aktivitas untuk Gradebook Phase 3 (§46).
        $this->app->singleton(GradeProviderInterface::class, LmsGradeProvider::class);

        // Phase 3 — integrasi nilai ke modul akademik (§40, §63).
        // Integrated  → provider SIAKAD (menulis ke `assessments`/`scores`).
        // Standalone  → provider kosong; gradebook LMS menjadi sumber resmi.
        $this->app->singleton(AcademicGradeProviderInterface::class, fn () => config('lms.standalone', false)
            ? new StandaloneAcademicGradeProvider
            : new SiakadAcademicGradeProvider);
    }

    public function boot(): void
    {
        $this->applyStoredSettings();

        // Migrasi modul berada di dalam modul agar dapat dipindahkan utuh.
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerGradebookObservers();
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
