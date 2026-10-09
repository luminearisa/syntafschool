<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Day;
use App\Models\Employee;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleChange;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A2 — Jadwal Pelajaran.
 *
 * Empat aturan bentrok punya masing-masing satu test tersendiri:
 * bentrok kelas, bentrok guru, bentrok ruang, dan jam terbalik.
 */
class ScheduleTest extends TestCase
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

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@syntaf.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function seedDays(): void
    {
        $days = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        foreach ($days as $id => $name) {
            Day::firstOrCreate(['id' => $id], ['name' => $name]);
        }
    }

    private function makeSemester(): Semester
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );

        return Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Ganjil'],
            ['start_date' => '2025-07-01', 'end_date' => '2025-12-31', 'is_active' => true],
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

    /** Daftarkan mapel ke kelas — prasyarat sebelum mapel boleh dijadwalkan. */
    private function registerSubject(SchoolClass $class, Subject $subject, ?Employee $teacher = null): ClassSubject
    {
        return ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'employee_id' => $teacher?->id,
            'academic_year_id' => $class->academic_year_id,
        ]);
    }

    private function makeEmployee(string $name = 'Budi Santoso', bool $isTeacher = true): Employee
    {
        return Employee::create([
            'full_name' => $name,
            'employee_type' => $isTeacher ? 'guru' : 'staf',
            'employment_status' => 'PNS',
            'is_teacher' => $isTeacher,
            'is_active' => true,
        ]);
    }

    private function makeRoom(string $code = 'R-101', string $name = 'Ruang 101'): Room
    {
        return Room::create([
            'code' => $code,
            'name' => $name,
            'type' => 'Kelas',
            'capacity' => 32,
            'is_active' => true,
        ]);
    }

    /**
     * Dunia minimal: satu kelas dengan Matematika terdaftar, satu guru, satu ruang.
     *
     * @return array{semester: Semester, class: SchoolClass, subject: Subject, teacher: Employee, room: Room}
     */
    private function world(): array
    {
        $semester = $this->makeSemester();
        $class = $this->makeClass($semester);
        $subject = $this->makeSubject();
        $teacher = $this->makeEmployee();
        $room = $this->makeRoom();

        $this->registerSubject($class, $subject, $teacher);

        return compact('semester', 'class', 'subject', 'teacher', 'room');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $world, array $overrides = []): array
    {
        return array_merge([
            'school_class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'employee_id' => $world['teacher']->id,
            'room_id' => $world['room']->id,
            'day_id' => 1,
            'semester_id' => $world['semester']->id,
            'start_time' => '07:00',
            'end_time' => '07:45',
        ], $overrides);
    }

    private function makeSchedule(array $world, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'school_class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'employee_id' => $world['teacher']->id,
            'room_id' => $world['room']->id,
            'day_id' => 1,
            'semester_id' => $world['semester']->id,
            'start_time' => '07:00:00',
            'end_time' => '07:45:00',
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // CRUD dasar
    // -----------------------------------------------------------------

    public function test_super_admin_can_list_schedules(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world);

        $this->getJson('/api/v1/schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.code', 'MTK')
            ->assertJsonPath('data.0.day.name', 'Senin')
            ->assertJsonPath('data.0.start_time', '07:00');
    }

    public function test_super_admin_can_create_schedule(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/schedules', $this->payload($world))
            ->assertCreated()
            ->assertJsonPath('data.start_time', '07:00')
            ->assertJsonPath('data.teacher.full_name', 'Budi Santoso');

        // Jam disimpan lengkap dengan detik agar perbandingan antar jam konsisten.
        $this->assertDatabaseHas('schedules', [
            'school_class_id' => $world['class']->id,
            'day_id' => 1,
            'start_time' => '07:00:00',
            'end_time' => '07:45:00',
        ]);
    }

    public function test_super_admin_can_update_and_delete_schedule(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        $this->putJson("/api/v1/schedules/{$schedule->id}", $this->payload($world, [
            'start_time' => '08:00',
            'end_time' => '08:45',
        ]))
            ->assertOk()
            ->assertJsonPath('data.start_time', '08:00');

        $this->deleteJson("/api/v1/schedules/{$schedule->id}")->assertOk();
        $this->assertDatabaseCount('schedules', 0);
    }

    // -----------------------------------------------------------------
    // Aturan bentrok 1 — kelas
    // -----------------------------------------------------------------

    public function test_rule_1_class_conflict_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world, ['start_time' => '07:00:00', 'end_time' => '07:45:00']);

        // Mapel lain di kelas yang sama, jam beririsan.
        $lain = $this->makeSubject('FIS', 'Fisika');
        $this->registerSubject($world['class'], $lain, $world['teacher']);

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'subject_id' => $lain->id,
            'start_time' => '07:30',
            'end_time' => '08:15',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_class_conflict_ignores_adjacent_times(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world, ['start_time' => '07:00:00', 'end_time' => '07:45:00']);

        $lain = $this->makeSubject('FIS', 'Fisika');
        $this->registerSubject($world['class'], $lain, $world['teacher']);

        // 07:45 tepat setelah 07:45 — bersebelahan, bukan bentrok.
        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'subject_id' => $lain->id,
            'start_time' => '07:45',
            'end_time' => '08:30',
        ]))->assertCreated();

        $this->assertDatabaseCount('schedules', 2);
    }

    // -----------------------------------------------------------------
    // Aturan bentrok 2 — guru
    // -----------------------------------------------------------------

    public function test_rule_2_teacher_conflict_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world);

        // Kelas lain, mapel lain, tapi guru yang sama pada jam yang sama.
        $kelasLain = $this->makeClass($world['semester'], 'X-IPA-2', 'X IPA 2');
        $lain = $this->makeSubject('FIS', 'Fisika');
        $this->registerSubject($kelasLain, $lain, $world['teacher']);

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'school_class_id' => $kelasLain->id,
            'subject_id' => $lain->id,
            'start_time' => '07:15',
            'end_time' => '08:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');

        $this->assertDatabaseCount('schedules', 1);
    }

    // -----------------------------------------------------------------
    // Aturan bentrok 3 — ruang
    // -----------------------------------------------------------------

    public function test_rule_3_room_conflict_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world);

        // Kelas lain, guru lain, tapi ruang yang sama pada jam yang sama.
        $kelasLain = $this->makeClass($world['semester'], 'X-IPA-2', 'X IPA 2');
        $lain = $this->makeSubject('FIS', 'Fisika');
        $guruLain = $this->makeEmployee('Siti Aminah');
        $this->registerSubject($kelasLain, $lain, $guruLain);

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'school_class_id' => $kelasLain->id,
            'subject_id' => $lain->id,
            'employee_id' => $guruLain->id,
            'start_time' => '07:15',
            'end_time' => '08:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');

        $this->assertDatabaseCount('schedules', 1);
    }

    // -----------------------------------------------------------------
    // Aturan bentrok 4 — jam terbalik
    // -----------------------------------------------------------------

    public function test_rule_4_inverted_time_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'start_time' => '09:00',
            'end_time' => '08:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');

        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_zero_length_schedule_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'start_time' => '07:00',
            'end_time' => '07:00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');
    }

    // -----------------------------------------------------------------
    // Aturan pendukung
    // -----------------------------------------------------------------

    public function test_schedule_does_not_conflict_with_itself_on_update(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        // Menggeser jam jadwal yang sama tidak boleh dianggap bentrok dengan dirinya.
        $this->putJson("/api/v1/schedules/{$schedule->id}", $this->payload($world, [
            'start_time' => '07:10',
            'end_time' => '07:55',
        ]))->assertOk();
    }

    public function test_subject_must_be_registered_in_the_class(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $asing = $this->makeSubject('BIO', 'Biologi'); // belum didaftarkan ke kelas

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'subject_id' => $asing->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }

    public function test_non_teacher_cannot_be_assigned(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $staf = $this->makeEmployee('Staf TU', false);

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'employee_id' => $staf->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_time_slot_must_match_the_day(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $slotSelasa = TimeSlot::create([
            'day_id' => 2,
            'slot_number' => 1,
            'label' => 'Jam ke-1',
            'start_time' => '07:00:00',
            'end_time' => '07:45:00',
            'type' => 'pelajaran',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/schedules', $this->payload($world, [
            'day_id' => 1,
            'time_slot_id' => $slotSelasa->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_slot_id');
    }

    // -----------------------------------------------------------------
    // Grid & cek bentrok
    // -----------------------------------------------------------------

    public function test_grid_returns_days_slots_and_entries(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        TimeSlot::create([
            'day_id' => 1,
            'slot_number' => 1,
            'label' => 'Jam ke-1',
            'start_time' => '07:00:00',
            'end_time' => '07:45:00',
            'type' => 'pelajaran',
            'is_active' => true,
        ]);

        $slot = TimeSlot::where('day_id', 1)->first();
        $this->makeSchedule($world, ['time_slot_id' => $slot->id]);

        $this->getJson('/api/v1/schedules/grid?semester_id='.$world['semester']->id.'&school_class_id='.$world['class']->id)
            ->assertOk()
            ->assertJsonCount(7, 'data.days')
            ->assertJsonCount(1, 'data.slots')
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.time_slot.slot_number', 1);
    }

    public function test_grid_filters_by_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world);

        $this->getJson('/api/v1/schedules/grid?semester_id='.$world['semester']->id.'&employee_id='.$world['teacher']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.entries');

        $guruLain = $this->makeEmployee('Guru Lain');

        $this->getJson('/api/v1/schedules/grid?semester_id='.$world['semester']->id.'&employee_id='.$guruLain->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.entries');
    }

    public function test_check_conflict_endpoint_reports_without_saving(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $this->makeSchedule($world);

        $this->postJson('/api/v1/schedules/check-conflict', [
            'school_class_id' => $world['class']->id,
            'day_id' => 1,
            'semester_id' => $world['semester']->id,
            'start_time' => '07:15',
            'end_time' => '08:00',
            'employee_id' => $world['teacher']->id,
            'room_id' => $world['room']->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.has_conflict', true);

        // Endpoint ini tidak boleh menyimpan apa pun.
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_check_conflict_endpoint_reports_clean_when_no_conflict(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $this->postJson('/api/v1/schedules/check-conflict', [
            'school_class_id' => $world['class']->id,
            'day_id' => 1,
            'semester_id' => $world['semester']->id,
            'start_time' => '07:00',
            'end_time' => '07:45',
        ])
            ->assertOk()
            ->assertJsonPath('data.has_conflict', false);
    }

    // -----------------------------------------------------------------
    // Perubahan jadwal — guru pengganti, pindah, batal
    // -----------------------------------------------------------------

    public function test_can_record_substitute_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);
        $pengganti = $this->makeEmployee('Guru Pengganti');

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", [
            'date' => '2026-09-15',
            'type' => 'pengganti',
            'substitute_employee_id' => $pengganti->id,
            'reason' => 'Guru utama sakit',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'pengganti')
            ->assertJsonPath('data.substitute_teacher.full_name', 'Guru Pengganti');

        // Jadwal pokok tidak berubah — perubahan harian terpisah.
        $this->assertSame($world['teacher']->id, $schedule->refresh()->employee_id);
        $this->assertDatabaseCount('schedule_changes', 1);
    }

    public function test_substitute_teacher_requires_a_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);
        $staf = $this->makeEmployee('Staf TU', false);

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", [
            'date' => '2026-09-15',
            'type' => 'pengganti',
            'substitute_employee_id' => $staf->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('substitute_employee_id');
    }

    public function test_substitute_teacher_must_not_be_busy_at_the_same_time(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world, ['day_id' => 2]); // Selasa

        // Guru pengganti sudah mengajar kelas lain pada Selasa jam yang sama.
        $kelasLain = $this->makeClass($world['semester'], 'X-IPA-2', 'X IPA 2');
        $lain = $this->makeSubject('FIS', 'Fisika');
        $pengganti = $this->makeEmployee('Guru Sibuk');
        $this->registerSubject($kelasLain, $lain, $pengganti);

        Schedule::create([
            'school_class_id' => $kelasLain->id,
            'subject_id' => $lain->id,
            'employee_id' => $pengganti->id,
            'day_id' => 2,
            'semester_id' => $world['semester']->id,
            'start_time' => '07:00:00',
            'end_time' => '07:45:00',
        ]);

        // 2026-09-15 adalah hari Selasa.
        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", [
            'date' => '2026-09-15',
            'type' => 'pengganti',
            'substitute_employee_id' => $pengganti->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('substitute_employee_id');
    }

    public function test_can_cancel_schedule_for_a_date(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", [
            'date' => '2026-09-15',
            'type' => 'batal',
            'reason' => 'Rapat guru',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'batal');
    }

    public function test_can_move_schedule_for_a_date(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", [
            'date' => '2026-09-15',
            'type' => 'pindah',
            'new_start_time' => '10:00',
            'new_end_time' => '10:45',
            'reason' => 'Ruang dipakai ujian',
        ])
            ->assertCreated()
            ->assertJsonPath('data.new_start_time', '10:00');
    }

    public function test_move_requires_valid_new_time_range(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", [
            'date' => '2026-09-15',
            'type' => 'pindah',
            'new_start_time' => '10:00',
            'new_end_time' => '09:00',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_end_time');
    }

    public function test_only_one_change_per_schedule_per_date(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        $payload = ['date' => '2026-09-15', 'type' => 'batal', 'reason' => 'Rapat guru'];

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", $payload)->assertCreated();

        $this->postJson("/api/v1/schedules/{$schedule->id}/changes", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertDatabaseCount('schedule_changes', 1);
    }

    public function test_can_list_and_delete_schedule_changes(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        $change = ScheduleChange::create([
            'schedule_id' => $schedule->id,
            'date' => '2026-09-15',
            'type' => 'batal',
            'reason' => 'Rapat guru',
        ]);

        $this->getJson('/api/v1/schedule-changes?date=2026-09-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.schedule.subject.name', 'Matematika');

        $this->deleteJson("/api/v1/schedule-changes/{$change->id}")->assertOk();
        $this->assertDatabaseCount('schedule_changes', 0);
    }

    public function test_deleting_schedule_removes_its_changes(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();
        $schedule = $this->makeSchedule($world);

        ScheduleChange::create([
            'schedule_id' => $schedule->id,
            'date' => '2026-09-15',
            'type' => 'batal',
        ]);

        $this->deleteJson("/api/v1/schedules/{$schedule->id}")->assertOk();
        $this->assertDatabaseCount('schedule_changes', 0);
    }

    // -----------------------------------------------------------------
    // Jam pelajaran
    // -----------------------------------------------------------------

    public function test_super_admin_can_manage_time_slots(): void
    {
        $this->actingAsRole('super-admin');

        $this->postJson('/api/v1/time-slots', [
            'day_id' => 1,
            'slot_number' => 1,
            'label' => 'Jam ke-1',
            'start_time' => '07:00',
            'end_time' => '07:45',
            'type' => 'pelajaran',
        ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Jam ke-1')
            ->assertJsonPath('data.start_time', '07:00');

        $this->getJson('/api/v1/time-slots?all=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_overlapping_time_slots_are_rejected(): void
    {
        $this->actingAsRole('super-admin');

        TimeSlot::create([
            'day_id' => 1, 'slot_number' => 1, 'label' => 'Jam ke-1',
            'start_time' => '07:00:00', 'end_time' => '07:45:00',
            'type' => 'pelajaran', 'is_active' => true,
        ]);

        $this->postJson('/api/v1/time-slots', [
            'day_id' => 1,
            'slot_number' => 2,
            'label' => 'Jam ke-2',
            'start_time' => '07:30',
            'end_time' => '08:15',
            'type' => 'pelajaran',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
    }

    public function test_duplicate_slot_number_on_same_day_is_rejected(): void
    {
        $this->actingAsRole('super-admin');

        TimeSlot::create([
            'day_id' => 1, 'slot_number' => 1, 'label' => 'Jam ke-1',
            'start_time' => '07:00:00', 'end_time' => '07:45:00',
            'type' => 'pelajaran', 'is_active' => true,
        ]);

        $this->postJson('/api/v1/time-slots', [
            'day_id' => 1,
            'slot_number' => 1,
            'label' => 'Jam ke-1 lagi',
            'start_time' => '08:00',
            'end_time' => '08:45',
            'type' => 'pelajaran',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slot_number');
    }

    public function test_can_generate_time_slots_from_template(): void
    {
        $this->actingAsRole('super-admin');

        $this->postJson('/api/v1/time-slots/generate', ['day_ids' => [1, 5]])
            ->assertCreated()
            ->assertJsonPath('data.created', 17) // Senin 10 slot + Jumat 7 slot
            ->assertJsonPath('data.skipped', 0);

        // Idempoten: dijalankan ulang tanpa overwrite tidak menggandakan.
        $this->postJson('/api/v1/time-slots/generate', ['day_ids' => [1, 5]])
            ->assertCreated()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 17);

        $this->assertDatabaseCount('time_slots', 17);
    }

    public function test_deleting_used_time_slot_detaches_schedule_instead_of_deleting_it(): void
    {
        $this->actingAsRole('super-admin');
        $world = $this->world();

        $slot = TimeSlot::create([
            'day_id' => 1, 'slot_number' => 1, 'label' => 'Jam ke-1',
            'start_time' => '07:00:00', 'end_time' => '07:45:00',
            'type' => 'pelajaran', 'is_active' => true,
        ]);

        $schedule = $this->makeSchedule($world, ['time_slot_id' => $slot->id]);

        $this->deleteJson("/api/v1/time-slots/{$slot->id}")->assertOk();

        // Jadwal tetap ada; hanya penanda jamnya yang lepas.
        $this->assertDatabaseCount('schedules', 1);
        $this->assertNull($schedule->refresh()->time_slot_id);
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function test_guru_can_view_but_not_create_schedules(): void
    {
        $this->actingAsRole('guru');
        $world = $this->world();

        $this->getJson('/api/v1/schedules')->assertOk();

        $this->postJson('/api/v1/schedules', $this->payload($world))->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/schedules')->assertUnauthorized();
        $this->getJson('/api/v1/time-slots')->assertUnauthorized();
    }
}
