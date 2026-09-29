<?php

namespace App\Modules\LMS\Database\Seeders;

use App\Modules\LMS\Application\Services\AssessmentResultService;
use App\Modules\LMS\Application\Services\CompetencyAssessmentService;
use App\Modules\LMS\Application\Services\CompetencyService;
use App\Modules\LMS\Application\Services\GradeItemService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\LearningOutcomeService;
use App\Modules\LMS\Application\Services\OutcomeAssessmentService;
use App\Modules\LMS\Application\Services\RubricAssessmentService;
use App\Modules\LMS\Application\Services\RubricService;
use App\Modules\LMS\Domain\Enums\CompetencyStatus;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data contoh Gradebook Phase 3 (§64).
 *
 * Mengisi kategori + bobot, komponen manual, skor seluruh siswa, menjalankan
 * mesin perhitungan, merilis nilai, serta memetakan learning outcome dan
 * kompetensi — supaya halaman gradebook, nilai siswa, dan nilai wali dapat
 * ditelusuri langsung setelah `php artisan db:seed`.
 *
 * Seeder ini idempoten: aman dijalankan berulang.
 */
class LmsGradebookDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Pilih course yang benar-benar punya siswa terdaftar supaya data contoh
        // gradebook langsung terisi nilai, rilis, dan capaian.
        $course = LmsCourse::query()
            ->withCount(['enrollments as student_count' => fn ($query) => $query->where('role', EnrollmentRole::STUDENT->value)])
            ->orderByDesc('student_count')
            ->orderBy('id')
            ->first();

        if ($course === null) {
            return;
        }

        $gradeBooks = app(GradebookService::class);
        $gradebook = $gradeBooks->ensureForCourse($course, $course->created_by === null ? null : (int) $course->created_by);
        $master = app(AssessmentResultService::class)->systemActor();

        if ($gradebook->items()->whereNotNull('activity_id')->doesntExist()) {
            app(GradeItemService::class)->syncFromCourse($gradebook, $master->id);
        }

        // Demo yang bisa berdiri sendiri: bila course belum punya siswa,
        // sediakan akun siswa (dan satu wali) agar gradebook dapat ditelusuri.
        $this->ensureStudents($course);

        $students = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($students === []) {
            return;
        }

        // Aktivitas bernilai yang sudah ada ikut masuk gradebook.
        app(AssessmentResultService::class)->pullFromActivities($gradebook, $master);

        foreach ($this->manualItems($gradebook, $master->id) as $index => $item) {
            foreach ($students as $position => $userId) {
                $score = $this->scoreFor($index, $position, (float) $item->maximum_grade);

                app(AssessmentResultService::class)->saveManual($item, $userId, [
                    'final_score' => $score,
                    'maximum_score' => (float) $item->maximum_grade,
                    'feedback' => $score >= 80 ? 'Kerja bagus, pertahankan ketelitiannya.' : 'Perhatikan langkah pengerjaan dan cek kembali.',
                    'feedback_meta' => [
                        'what_went_well' => 'Struktur pengerjaan runtut.',
                        'what_to_improve' => 'Ketelitian pada bagian akhir.',
                    ],
                ], $master);
            }
        }

        // Nilai aktivitas (tugas/kuis) juga diisi supaya kolom aktivitas pada
        // tabel nilai dan capaian learning outcome tidak kosong.
        foreach ($gradebook->items()->whereNotNull('activity_id')->orderBy('id')->get() as $index => $activityItem) {
            foreach ($students as $position => $userId) {
                app(AssessmentResultService::class)->saveManual($activityItem, $userId, [
                    'final_score' => $this->scoreFor($index + 1, $position, (float) $activityItem->maximum_grade),
                    'maximum_score' => (float) $activityItem->maximum_grade,
                    'feedback' => 'Umpan balik aktivitas contoh.',
                ], $master);
            }
        }

        $this->seedOutcomes($course, $master->id);
        $this->seedRubric($course, $gradebook, $students, $master->id);

        // Rilis nilai supaya tampilan siswa & wali langsung terisi (§18).
        $releases = app(GradeReleaseService::class);

        foreach ($students as $index => $userId) {
            // Siswa terakhir dibiarkan belum dirilis agar status "menunggu" terlihat.
            if ($index === count($students) - 1) {
                continue;
            }

            try {
                $releases->releaseStudent($gradebook->refresh(), $userId, $master, notify: false);
            } catch (\Throwable) {
                // Bobot belum lengkap/gradebook dibekukan: lewati tanpa menggagalkan seeding.
            }
        }
    }

    /**
     * Komponen manual contoh: Ujian Tulis (30%), Praktik (20%).
     *
     * @return list<\App\Modules\LMS\Models\LmsGradeItem>
     */
    private function manualItems(LmsGradebook $gradebook, int $actorId): array
    {
        $definitions = [
            ['name' => 'Ujian Tulis', 'weight' => 30.0, 'maximum_grade' => 100.0],
            ['name' => 'Praktik Lapangan', 'weight' => 20.0, 'maximum_grade' => 100.0],
        ];

        $items = [];

        foreach ($definitions as $definition) {
            // Idempoten: seeder boleh dijalankan berulang tanpa menggandakan komponen.
            $existing = $gradebook->items()->where('name', $definition['name'])->first();

            if ($existing !== null) {
                $items[] = $existing;

                continue;
            }

            $items[] = app(GradeItemService::class)->create($gradebook, [
                'name' => $definition['name'],
                'source' => 'MANUAL',
                'maximum_grade' => $definition['maximum_grade'],
                'weight' => $definition['weight'],
                'include_in_final' => true,
            ], app(AssessmentResultService::class)->systemActor());
        }

        return $items;
    }

    /** Skor contoh yang bervariasi per siswa namun deterministik. */
    private function scoreFor(int $itemIndex, int $studentIndex, float $maximum): float
    {
        $base = [88.0, 76.0, 92.0][$studentIndex % 3] ?? 80.0;
        $offset = $itemIndex === 0 ? -4.0 : 6.0;

        return max(0.0, min($maximum, $base + $offset));
    }

    private function seedOutcomes(LmsCourse $course, int $actorId): void
    {
        $actor = app(AssessmentResultService::class)->systemActor();
        $service = app(LearningOutcomeService::class);

        if ($course->learningOutcomes()->doesntExist()) {
            $activities = LmsActivity::query()->where('course_id', $course->id)->orderBy('sort_order')->pluck('id')->all();

            $statements = [
                'Memahami konsep dasar materi course ini.',
                'Menerapkan konsep pada masalah nyata.',
            ];

            foreach ($statements as $index => $statement) {
                $service->create($course, [
                    'statement' => $statement,
                    'code' => 'LO'.($index + 1),
                    'activity_ids' => $activities === [] ? [] : [array_slice($activities, $index, 1)[0] ?? $activities[0]],
                ], $actor);
            }
        }

        // Capaian selalu dihitung ulang supaya nilai contoh langsung tercermin.
        $userIds = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', 'STUDENT')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($course->learningOutcomes()->get() as $outcome) {
            app(OutcomeAssessmentService::class)->recalculate($outcome, $userIds, $actor);
        }

        $this->seedCompetencies($course, $actorId);
    }

    private function seedCompetencies(LmsCourse $course, int $actorId): void
    {
        if ($course->competencies()->exists()) {
            return;
        }

        $actor = app(AssessmentResultService::class)->systemActor();

        $framework = app(CompetencyService::class)->createFramework([
            'name' => 'Kompetensi Abad 21',
            'code' => 'K21',
        ], $actor);

        $competencies = [
            ['name' => 'Berpikir Kritis', 'code' => 'K21-CRIT'],
            ['name' => 'Kolaborasi', 'code' => 'K21-COLL'],
        ];

        $studentIds = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', 'STUDENT')
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($competencies as $competencyData) {
            $competency = app(CompetencyService::class)->createCompetency($framework, $competencyData, $actor);
            app(CompetencyService::class)->mapToCourse($course, [$competency->id], $actor);

            foreach ($studentIds as $index => $userId) {
                app(CompetencyAssessmentService::class)->assess(
                    $competency,
                    $userId,
                    $index % 2 === 0 ? CompetencyStatus::COMPETENT : CompetencyStatus::DEVELOPING,
                    $actor,
                    $course,
                    ['note' => 'Dinilai dari observasi proyek kelompok.'],
                );
            }
        }
    }

    /** @param list<int> $students */
    private function seedRubric(LmsCourse $course, LmsGradebook $gradebook, array $students, int $actorId): void
    {
        if ($course->id === null) {
            return;
        }

        $actor = app(AssessmentResultService::class)->systemActor();

        $existing = \App\Modules\LMS\Models\LmsRubric::query()
            ->where('name', 'Rubric Presentasi (contoh)')
            ->first();

        $rubric = $existing ?? app(RubricService::class)->create([
            'course_id' => $course->id,
            'name' => 'Rubric Presentasi (contoh)',
            'description' => 'Contoh rubric siap pakai untuk presentasi kelompok.',
            'grading_method' => 'POINTS',
            'criteria' => [
                ['name' => 'Content', 'levels' => [['name' => 'Excellent', 'score' => 4], ['name' => 'Good', 'score' => 3], ['name' => 'Fair', 'score' => 2]]],
                ['name' => 'Structure', 'levels' => [['name' => 'Excellent', 'score' => 4], ['name' => 'Good', 'score' => 3], ['name' => 'Fair', 'score' => 2]]],
                ['name' => 'Presentation', 'levels' => [['name' => 'Excellent', 'score' => 4], ['name' => 'Good', 'score' => 3], ['name' => 'Fair', 'score' => 2]]],
            ],
        ], $actor);

        $rubric->load('criteria.levels');

        // Idempoten: jangan menggandakan komponen rubric bila seeder dijalankan ulang.
        $item = $gradebook->items()->where('name', 'Presentasi Kelompok')->first()
            ?? app(GradeItemService::class)->create($gradebook, [
                'name' => 'Presentasi Kelompok',
                'source' => 'MANUAL',
                'maximum_grade' => 100,
                'weight' => 50,
                'rubric_id' => $rubric->id,
            ], $actor);

        foreach ($students as $index => $userId) {
            $selections = [];

            foreach ($rubric->criteria as $criterion) {
                $level = $index % 2 === 0
                    ? $criterion->levels->firstWhere('name', 'Excellent')
                    : $criterion->levels->firstWhere('name', 'Good');

                $selections[$criterion->id] = [
                    'level_id' => (int) ($level?->id ?? $criterion->levels->first()->id),
                    'comment' => 'Terlihat dari cara memaparkan materi.',
                ];
            }

            app(RubricAssessmentService::class)->assess(
                app(RubricAssessmentService::class)->start($rubric, $userId, $item, null, $actor),
                $selections,
                $actor,
                true,
                'Presentasi berjalan lancar.',
            );
        }
    }

    /**
     * Sediakan siswa demo + satu akun wali bila course belum punya peserta.
     *
     * Akun dibuat hanya bila belum ada, sehingga seeder tetap idempoten.
     */
    private function ensureStudents(LmsCourse $course, int $limit = 3): void
    {
        $existing = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->count();

        if ($existing >= $limit) {
            return;
        }

        $students = Student::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->take($limit)
            ->get();

        foreach ($students as $index => $student) {
            $user = User::query()
                ->where('userable_type', Student::class)
                ->where('userable_id', $student->id)
                ->first();

            if ($user === null) {
                $user = User::query()->firstOrCreate(
                    ['email' => 'siswa'.$student->id.'@syntaf.sch.id'],
                    [
                        'name' => $student->full_name,
                        'username' => 'siswa'.$student->id,
                        'password' => Hash::make('password'),
                        'is_active' => true,
                    ],
                );

                $user->forceFill(['userable_type' => Student::class, 'userable_id' => $student->id])->save();
            }

            $user->syncRoles(['siswa']);

            LmsEnrollment::query()->firstOrCreate(
                ['course_id' => $course->id, 'user_id' => $user->id],
                [
                    'student_id' => $student->id,
                    'role' => EnrollmentRole::STUDENT->value,
                    'status' => 'ACTIVE',
                    'enrollment_method' => 'MANUAL',
                    'enrolled_at' => now(),
                ],
            );

            if ($index === 0) {
                $this->ensureGuardian($student);
            }
        }
    }

    /** Satu akun wali untuk siswa pertama agar tampilan nilai wali dapat diuji. */
    private function ensureGuardian(Student $student): void
    {
        // Tabel `guardians` ERP tidak punya kolom `is_active`.
        $guardian = Guardian::query()->firstOrCreate(
            ['phone' => '081200000001'],
            ['full_name' => 'Wali '.$student->full_name, 'email' => 'wali.'.$student->id.'@syntaf.sch.id'],
        );

        // Skema ERP memakai `guardian_relation_id` + `is_primary`.
        StudentGuardian::query()->firstOrCreate(
            ['student_id' => $student->id, 'guardian_id' => $guardian->id],
            ['is_primary' => true],
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'wali'.$student->id.'@syntaf.sch.id'],
            [
                'name' => 'Wali '.$student->full_name,
                'username' => 'wali'.$student->id,
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        $user->syncRoles(['wali-murid']);

        PpdbParentAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['guardian_id' => $guardian->id, 'account_status' => 'active'],
        );
    }
}
