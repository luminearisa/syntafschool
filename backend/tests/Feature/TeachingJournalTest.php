<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\LessonPlan;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\TeachingJournal;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A11a — Jurnal Mengajar & RPP / Modul Ajar.
 *
 * Setiap aturan bisnis punya test tersendiri: kepemilikan guru, kecocokan
 * kelas/mapel yang diampu, rentang semester, batas realisasi JP, jurnal ganda,
 * penguncian jurnal selesai, alur approval RPP, dan RBAC.
 */
class TeachingJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seedDays();
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function seedDays(): void
    {
        $names = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        foreach ($names as $id => $name) {
            DB::table('days')->updateOrInsert(
                ['id' => $id],
                ['name' => $name, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@syntaf.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Akun guru yang tertaut ke satu pegawai, sehingga aturan kepemilikan aktif.
     */
    private function actingAsTeacher(Employee $teacher, array $extraPermissions = []): User
    {
        $user = User::factory()->create([
            'email' => "guru{$teacher->id}@syntaf.test",
            'is_active' => true,
            'userable_type' => Employee::class,
            'userable_id' => $teacher->id,
        ]);
        $user->syncRoles(['guru']);

        foreach ($extraPermissions as $permission) {
            $user->givePermissionTo($permission);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    private function makeSemester(): Semester
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );

        return Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Ganjil'],
            ['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'is_active' => true],
        );
    }

    private function makeClass(Semester $semester, string $code = 'X-IPA-1', string $name = 'X IPA 1'): SchoolClass
    {
        return SchoolClass::create([
            'academic_year_id' => $semester->academic_year_id,
            'name' => $name,
            'code' => $code,
            'capacity' => 32,
            'is_active' => true,
        ]);
    }

    private function makeSubject(string $code = 'MTK', string $name = 'Matematika'): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'category' => 'Wajib',
            'credit' => 4,
            'is_active' => true,
        ]);
    }

    private function makeTeacher(string $name = 'Budi Santoso'): Employee
    {
        return Employee::create([
            'full_name' => $name,
            'employee_type' => 'guru',
            'employment_status' => 'PNS',
            'is_teacher' => true,
            'is_active' => true,
        ]);
    }

    private function makeTimeSlot(int $dayId, int $slotNumber): TimeSlot
    {
        return TimeSlot::create([
            'day_id' => $dayId,
            'slot_number' => $slotNumber,
            'label' => "Jam ke-{$slotNumber}",
            'start_time' => sprintf('%02d:00:00', 6 + $slotNumber),
            'end_time' => sprintf('%02d:45:00', 6 + $slotNumber),
            'type' => 'pelajaran',
            'is_active' => true,
        ]);
    }

    /**
     * Dunia minimal: satu kelas dengan Matematika diampu satu guru (alokasi 4 JP).
     *
     * @return array{semester: Semester, class: SchoolClass, subject: Subject, teacher: Employee}
     */
    private function world(): array
    {
        $semester = $this->makeSemester();
        $class = $this->makeClass($semester);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'employee_id' => $teacher->id,
            'academic_year_id' => $class->academic_year_id,
            'weekly_hours' => 4,
        ]);

        return compact('semester', 'class', 'subject', 'teacher');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function journalPayload(array $world, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'topic' => 'Persamaan Linear Satu Variabel',
            'material' => 'Bab 2',
            'activities' => 'Diskusi dan latihan terbimbing.',
            'student_present_count' => 30,
            'realized_jp' => 2,
            'status' => TeachingJournal::STATUS_PLANNED,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeJournal(array $world, array $overrides = []): TeachingJournal
    {
        return TeachingJournal::create(array_merge([
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
            'semester_id' => $world['semester']->id,
            'date' => '2026-09-10',
            'topic' => 'Persamaan Linear Satu Variabel',
            'student_present_count' => 30,
            'realized_jp' => 2,
            'status' => TeachingJournal::STATUS_PLANNED,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lessonPlanPayload(array $world, array $overrides = []): array
    {
        return array_merge([
            'teacher_id' => $world['teacher']->id,
            'subject_id' => $world['subject']->id,
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'code' => 'RPP-MTK-01',
            'title' => 'Modul Ajar Persamaan Linear',
            'learning_objectives' => ['Menjelaskan persamaan linear.', 'Menyelesaikan soal sederhana.'],
            'activities' => ['Diskusi kelompok.', 'Latihan mandiri.'],
            'assessment_plan' => 'Kuis singkat.',
            'media' => 'Papan tulis dan LKPD.',
            'references' => 'Buku paket bab 2.',
            'duration_jp' => 2,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLessonPlan(array $world, array $overrides = []): LessonPlan
    {
        return LessonPlan::create(array_merge([
            'teacher_id' => $world['teacher']->id,
            'subject_id' => $world['subject']->id,
            'class_id' => $world['class']->id,
            'semester_id' => $world['semester']->id,
            'code' => 'RPP-MTK-01',
            'title' => 'Modul Ajar Persamaan Linear',
            'duration_jp' => 2,
            'status' => LessonPlan::STATUS_DRAFT,
        ], $overrides));
    }

    private function makeSchedule(array $world, int $dayId, string $start = '07:00:00', ?int $timeSlotId = null): Schedule
    {
        return Schedule::create([
            'school_class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'employee_id' => $world['teacher']->id,
            'day_id' => $dayId,
            'semester_id' => $world['semester']->id,
            'time_slot_id' => $timeSlotId,
            'start_time' => $start,
            'end_time' => '08:30:00',
        ]);
    }

    // -----------------------------------------------------------------
    // CRUD jurnal
    // -----------------------------------------------------------------

    public function test_super_admin_can_list_journals(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeJournal($world);

        $this->getJson('/api/v1/teaching-journals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.topic', 'Persamaan Linear Satu Variabel')
            ->assertJsonPath('data.0.subject.code', 'MTK')
            ->assertJsonPath('data.0.teacher.full_name', 'Budi Santoso')
            ->assertJsonPath('data.0.school_class.name', 'X IPA 1');
    }

    public function test_journal_list_can_filter_by_class_status_and_date(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeJournal($world, ['date' => '2026-09-05', 'status' => TeachingJournal::STATUS_PLANNED]);
        $this->makeJournal($world, ['date' => '2026-09-20', 'status' => TeachingJournal::STATUS_COMPLETED]);

        $this->getJson('/api/v1/teaching-journals?filter[status]=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-09-20');

        $this->getJson('/api/v1/teaching-journals?filter[class_id]='.$world['class']->id.'&from=2026-09-10&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', TeachingJournal::STATUS_COMPLETED);
    }

    public function test_super_admin_can_create_journal(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world))
            ->assertCreated()
            ->assertJsonPath('data.topic', 'Persamaan Linear Satu Variabel')
            ->assertJsonPath('data.status', TeachingJournal::STATUS_PLANNED)
            ->assertJsonPath('data.is_completed', false);

        $this->assertDatabaseHas('teaching_journals', [
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'teacher_id' => $world['teacher']->id,
        ]);
    }

    public function test_journal_show_returns_relations(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $journal = $this->makeJournal($world);

        $this->getJson("/api/v1/teaching-journals/{$journal->id}")
            ->assertOk()
            ->assertJsonPath('data.school_class.name', 'X IPA 1')
            ->assertJsonPath('data.subject.code', 'MTK')
            ->assertJsonPath('data.teacher.full_name', 'Budi Santoso');
    }

    public function test_super_admin_can_update_journal(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $journal = $this->makeJournal($world);

        $this->putJson("/api/v1/teaching-journals/{$journal->id}", $this->journalPayload($world, [
            'topic' => 'Persamaan Linear (Revisi)',
            'status' => TeachingJournal::STATUS_ONGOING,
        ]))
            ->assertOk()
            ->assertJsonPath('data.topic', 'Persamaan Linear (Revisi)')
            ->assertJsonPath('data.status', TeachingJournal::STATUS_ONGOING);
    }

    public function test_super_admin_can_delete_journal(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $journal = $this->makeJournal($world);

        $this->deleteJson("/api/v1/teaching-journals/{$journal->id}")->assertOk();

        $this->assertSoftDeleted('teaching_journals', ['id' => $journal->id]);
    }

    // -----------------------------------------------------------------
    // Aturan bisnis jurnal
    // -----------------------------------------------------------------

    public function test_guru_can_create_own_journal(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world, ['teacher_id' => $world['teacher']->id]))
            ->assertCreated()
            ->assertJsonPath('data.teacher_id', $world['teacher']->id);
    }

    public function test_guru_cannot_create_journal_for_another_teacher(): void
    {
        $world = $this->world();
        $other = $this->makeTeacher('Ani Lestari');
        $this->actingAsTeacher($world['teacher']);

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world, ['teacher_id' => $other->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('teacher_id');

        $this->assertDatabaseCount('teaching_journals', 0);
    }

    public function test_journal_for_subject_not_taught_is_rejected(): void
    {
        $world = $this->world();
        $otherSubject = $this->makeSubject('FIS', 'Fisika');
        $this->actingAsTeacher($world['teacher']);

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world, [
            'subject_id' => $otherSubject->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');

        $this->assertDatabaseCount('teaching_journals', 0);
    }

    public function test_journal_date_outside_semester_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world, ['date' => '2026-01-05']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertDatabaseCount('teaching_journals', 0);
    }

    public function test_realized_jp_exceeding_allocation_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world, ['realized_jp' => 5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('realized_jp');

        $this->assertDatabaseCount('teaching_journals', 0);
    }

    public function test_realized_jp_must_be_at_least_one(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world, ['realized_jp' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('realized_jp');
    }

    public function test_duplicate_journal_slot_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $slot = $this->makeTimeSlot(1, 1);

        $payload = $this->journalPayload($world, ['time_slot_id' => $slot->id, 'date' => '2026-09-10']);

        $this->postJson('/api/v1/teaching-journals', $payload)->assertCreated();

        $this->postJson('/api/v1/teaching-journals', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_slot_id');

        $this->assertDatabaseCount('teaching_journals', 1);
    }

    public function test_completed_journal_is_locked_for_regular_teacher(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $journal = $this->makeJournal($world, ['status' => TeachingJournal::STATUS_COMPLETED]);

        $this->putJson("/api/v1/teaching-journals/{$journal->id}", $this->journalPayload($world, [
            'topic' => 'Mencoba mengubah jurnal selesai',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_completed_journal_can_be_updated_by_approver(): void
    {
        $world = $this->world();
        $journal = $this->makeJournal($world, ['status' => TeachingJournal::STATUS_COMPLETED]);
        $this->actingAsRole('kepala-sekolah');

        $this->putJson("/api/v1/teaching-journals/{$journal->id}", $this->journalPayload($world, [
            'topic' => 'Koreksi oleh pengawas',
        ]))
            ->assertOk()
            ->assertJsonPath('data.topic', 'Koreksi oleh pengawas');
    }

    public function test_cancelled_journal_cannot_be_completed_without_approval(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $journal = $this->makeJournal($world, ['status' => TeachingJournal::STATUS_CANCELLED]);

        $this->putJson("/api/v1/teaching-journals/{$journal->id}", $this->journalPayload($world, [
            'status' => TeachingJournal::STATUS_COMPLETED,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_complete_sets_status_and_realized_jp(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $journal = $this->makeJournal($world, ['status' => TeachingJournal::STATUS_PLANNED, 'realized_jp' => 0]);

        $this->postJson("/api/v1/teaching-journals/{$journal->id}/complete", [
            'realized_jp' => 2,
            'student_present_count' => 31,
            'notes' => 'Terlaksana penuh.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TeachingJournal::STATUS_COMPLETED)
            ->assertJsonPath('data.realized_jp', 2)
            ->assertJsonPath('data.is_completed', true);

        $journal->refresh();
        $this->assertSame(TeachingJournal::STATUS_COMPLETED, $journal->status);
        $this->assertSame(2, (int) $journal->realized_jp);
        $this->assertSame(31, (int) $journal->student_present_count);
    }

    public function test_complete_rejects_realized_jp_over_allocation(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $journal = $this->makeJournal($world);

        $this->postJson("/api/v1/teaching-journals/{$journal->id}/complete", ['realized_jp' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('realized_jp');
    }

    public function test_complete_on_cancelled_journal_is_rejected(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);
        $journal = $this->makeJournal($world, ['status' => TeachingJournal::STATUS_CANCELLED]);

        $this->postJson("/api/v1/teaching-journals/{$journal->id}/complete", ['realized_jp' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // -----------------------------------------------------------------
    // Jadwal hari ini & rekap
    // -----------------------------------------------------------------

    public function test_today_marks_journaled_and_pending_schedules(): void
    {
        $world = $this->world();
        $this->actingAsTeacher($world['teacher']);

        $date = '2026-09-15';
        $dayId = Carbon::parse($date)->dayOfWeekIso;
        $slotA = $this->makeTimeSlot($dayId, 1);
        $slotB = $this->makeTimeSlot($dayId, 2);

        $this->makeSchedule($world, $dayId, '07:00:00', $slotA->id);
        $this->makeSchedule($world, $dayId, '08:00:00', $slotB->id);

        // Hanya jadwal pertama yang sudah dijurnal.
        $this->makeJournal($world, ['date' => $date, 'time_slot_id' => $slotA->id]);

        $this->getJson("/api/v1/teaching-journals/today?date={$date}")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.journaled', 1)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.items.0.has_journal', true)
            ->assertJsonPath('data.items.1.has_journal', false);
    }

    public function test_today_requires_teacher_context(): void
    {
        $this->actingAsRole('super-admin');
        $this->world();

        $this->getJson('/api/v1/teaching-journals/today')
            ->assertStatus(422)
            ->assertJsonValidationErrors('teacher_id');
    }

    public function test_recap_counts_realized_vs_scheduled(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->makeSchedule($world, 1, '07:00:00');
        $this->makeSchedule($world, 2, '07:00:00');

        $this->makeJournal($world, ['status' => TeachingJournal::STATUS_COMPLETED, 'realized_jp' => 2]);
        $this->makeJournal($world, ['date' => '2026-09-11', 'status' => TeachingJournal::STATUS_PLANNED, 'realized_jp' => 0]);

        $this->getJson('/api/v1/teaching-journals/recap?semester_id='.$world['semester']->id.'&teacher_id='.$world['teacher']->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.scheduled_jp', 2)
            ->assertJsonPath('data.items.0.realized_jp', 2)
            ->assertJsonPath('data.items.0.journal_count', 2)
            ->assertJsonPath('data.items.0.completed_count', 1)
            ->assertJsonPath('data.totals.realized_jp', 2);
    }

    // -----------------------------------------------------------------
    // CRUD & alur approval RPP
    // -----------------------------------------------------------------

    public function test_super_admin_can_create_lesson_plan(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/lesson-plans', $this->lessonPlanPayload($world))
            ->assertCreated()
            ->assertJsonPath('data.code', 'RPP-MTK-01')
            ->assertJsonPath('data.status', LessonPlan::STATUS_DRAFT)
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.learning_objectives.0', 'Menjelaskan persamaan linear.');

        $this->assertDatabaseHas('lesson_plans', ['code' => 'RPP-MTK-01', 'status' => LessonPlan::STATUS_DRAFT]);
    }

    public function test_lesson_plan_list_can_filter_and_search(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeLessonPlan($world, ['code' => 'RPP-A', 'title' => 'Modul Bilangan Bulat', 'status' => LessonPlan::STATUS_DRAFT]);
        $this->makeLessonPlan($world, ['code' => 'RPP-B', 'title' => 'Modul Statistika', 'status' => LessonPlan::STATUS_APPROVED]);

        $this->getJson('/api/v1/lesson-plans?filter[status]=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'RPP-B');

        $this->getJson('/api/v1/lesson-plans?search=bilangan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'RPP-A');
    }

    public function test_super_admin_can_update_draft_lesson_plan(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world);

        $this->putJson("/api/v1/lesson-plans/{$plan->id}", $this->lessonPlanPayload($world, [
            'title' => 'Modul Ajar Persamaan Linear (Revisi)',
            'duration_jp' => 3,
        ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Modul Ajar Persamaan Linear (Revisi)')
            ->assertJsonPath('data.duration_jp', 3);
    }

    public function test_lesson_plan_content_change_blocked_when_submitted(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world, ['status' => LessonPlan::STATUS_SUBMITTED]);

        $this->putJson("/api/v1/lesson-plans/{$plan->id}", $this->lessonPlanPayload($world, ['title' => 'Diubah saat diajukan']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_lesson_plan_submit_transitions_to_submitted(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world);

        $this->postJson("/api/v1/lesson-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', LessonPlan::STATUS_SUBMITTED);

        $this->assertSame(LessonPlan::STATUS_SUBMITTED, $plan->refresh()->status);
    }

    public function test_lesson_plan_submit_twice_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world, ['status' => LessonPlan::STATUS_SUBMITTED]);

        $this->postJson("/api/v1/lesson-plans/{$plan->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_lesson_plan_approve_sets_approver(): void
    {
        $user = $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world, ['status' => LessonPlan::STATUS_SUBMITTED]);

        $this->postJson("/api/v1/lesson-plans/{$plan->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', LessonPlan::STATUS_APPROVED);

        $plan->refresh();
        $this->assertSame(LessonPlan::STATUS_APPROVED, $plan->status);
        $this->assertSame($user->id, $plan->approved_by);
        $this->assertNotNull($plan->approved_at);
    }

    public function test_lesson_plan_approve_on_draft_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world);

        $this->postJson("/api/v1/lesson-plans/{$plan->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_lesson_plan_reject_requires_reason(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world, ['status' => LessonPlan::STATUS_SUBMITTED]);

        $this->postJson("/api/v1/lesson-plans/{$plan->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rejection_reason');
    }

    public function test_lesson_plan_reject_sets_status_and_reason(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world, ['status' => LessonPlan::STATUS_SUBMITTED]);

        $this->postJson("/api/v1/lesson-plans/{$plan->id}/reject", [
            'rejection_reason' => 'Indikator capaian belum terukur.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', LessonPlan::STATUS_REJECTED)
            ->assertJsonPath('data.rejection_reason', 'Indikator capaian belum terukur.');
    }

    public function test_rejected_lesson_plan_returns_to_draft_on_revision(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $plan = $this->makeLessonPlan($world, [
            'status' => LessonPlan::STATUS_REJECTED,
            'rejection_reason' => 'Perbaiki tujuan.',
        ]);

        $this->putJson("/api/v1/lesson-plans/{$plan->id}", $this->lessonPlanPayload($world, [
            'title' => 'Modul Ajar Persamaan Linear (Revisi)',
        ]))
            ->assertOk()
            ->assertJsonPath('data.status', LessonPlan::STATUS_DRAFT)
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_teacher_cannot_edit_another_teachers_lesson_plan(): void
    {
        $world = $this->world();
        $other = $this->makeTeacher('Ani Lestari');
        $plan = $this->makeLessonPlan($world);

        $this->actingAsTeacher($other);

        $this->putJson("/api/v1/lesson-plans/{$plan->id}", $this->lessonPlanPayload($world, ['teacher_id' => $other->id]))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_guru_can_view_journals(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/teaching-journals')->assertOk();
    }

    public function test_siswa_cannot_create_journal(): void
    {
        $this->actingAsRole('siswa');
        $world = $this->world();

        $this->postJson('/api/v1/teaching-journals', $this->journalPayload($world))->assertForbidden();
    }

    public function test_guest_returns_401(): void
    {
        $this->getJson('/api/v1/teaching-journals')->assertUnauthorized();
        $this->getJson('/api/v1/lesson-plans')->assertUnauthorized();
    }
}
