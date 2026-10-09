<?php

namespace Tests\Feature;

use App\Models\AcademicEvent;
use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\EffectiveDay;
use App\Models\EventType;
use App\Models\Semester;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A4 — Kalender Akademik & Hari Efektif.
 *
 * Setiap aturan bisnis punya test tersendiri: urutan tanggal, konsistensi
 * semester vs tahun ajaran, bentrok libur vs libur, generate hari efektif
 * (Minggu & libur dikecualikan), overwrite, duplikat tanggal, ringkasan,
 * dan RBAC.
 */
class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    private function makeYear(string $name = '2026/2027'): AcademicYear
    {
        return AcademicYear::firstOrCreate(
            ['name' => $name],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    private function makeSemester(
        AcademicYear $year,
        string $name = 'Ganjil',
        string $start = '2026-07-06',
        string $end = '2026-07-19',
    ): Semester {
        return Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => $name],
            ['start_date' => $start, 'end_date' => $end, 'is_active' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEventType(array $overrides = []): EventType
    {
        $code = $overrides['code'] ?? 'KEGIATAN_SEKOLAH';

        return EventType::firstOrCreate(
            ['code' => $code],
            array_merge([
                'name' => 'Kegiatan Sekolah',
                'color' => '#0ea5e9',
                'is_holiday' => false,
                'is_active' => true,
            ], $overrides),
        );
    }

    private function makeHolidayType(): EventType
    {
        return $this->makeEventType([
            'code' => 'LIBUR_NASIONAL',
            'name' => 'Libur Nasional',
            'color' => '#ef4444',
            'is_holiday' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvent(Semester $semester, EventType $type, array $overrides = []): AcademicEvent
    {
        return AcademicEvent::create(array_merge([
            'semester_id' => $semester->id,
            'event_type_id' => $type->id,
            'title' => 'Event Akademik',
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-08',
            'is_holiday' => false,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Semester $semester, EventType $type, array $overrides = []): array
    {
        return array_merge([
            'semester_id' => $semester->id,
            'event_type_id' => $type->id,
            'title' => 'Penilaian Tengah Semester',
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-10',
            'is_holiday' => false,
            'is_active' => true,
        ], $overrides);
    }

    private function makeLevel(): EducationLevel
    {
        return EducationLevel::create([
            'name' => 'Kelas 7',
            'level_number' => 7,
            'jenjang' => 'SMP',
            'is_active' => true,
        ]);
    }

    /**
     * Baca nilai `is_school_day` untuk satu tanggal (kolom date SQLite memuat jam,
     * jadi pembacaan lewat `whereDate`).
     */
    private function isSchoolDay(Semester $semester, string $date): bool
    {
        return (bool) EffectiveDay::where('semester_id', $semester->id)
            ->whereDate('date', $date)
            ->value('is_school_day');
    }

    // =================================================================
    // Jenis event
    // =================================================================

    public function test_can_list_event_types(): void
    {
        $this->actingAsRole('admin-sekolah');
        $this->makeEventType(['code' => 'UTS', 'name' => 'Ujian Tengah Semester']);
        $this->makeHolidayType();

        $this->getJson('/api/v1/event-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_can_create_event_type(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/event-types', [
            'code' => 'RAPAT_GURU',
            'name' => 'Rapat Guru',
            'color' => '#8b5cf6',
            'is_holiday' => false,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'RAPAT_GURU')
            ->assertJsonPath('data.is_holiday', false);

        $this->assertDatabaseHas('event_types', ['code' => 'RAPAT_GURU', 'is_holiday' => false]);
    }

    public function test_event_type_code_must_be_unique(): void
    {
        $this->actingAsRole('admin-sekolah');
        $this->makeEventType(['code' => 'UTS', 'name' => 'Ujian Tengah Semester']);

        $this->postJson('/api/v1/event-types', [
            'code' => 'UTS',
            'name' => 'Duplikat',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_can_update_event_type(): void
    {
        $this->actingAsRole('admin-sekolah');
        $type = $this->makeEventType(['code' => 'UTS', 'name' => 'Ujian Tengah Semester']);

        $this->putJson("/api/v1/event-types/{$type->id}", [
            'code' => 'UTS',
            'name' => 'Penilaian Tengah Semester',
            'is_holiday' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Penilaian Tengah Semester');

        $this->assertDatabaseHas('event_types', ['id' => $type->id, 'name' => 'Penilaian Tengah Semester']);
    }

    public function test_can_delete_event_type(): void
    {
        $this->actingAsRole('admin-sekolah');
        $type = $this->makeEventType(['code' => 'RAPAT_GURU']);

        $this->deleteJson("/api/v1/event-types/{$type->id}")->assertOk();

        $this->assertSoftDeleted('event_types', ['id' => $type->id]);
    }

    public function test_cannot_delete_event_type_that_is_in_use(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType(['code' => 'UTS']);
        $this->makeEvent($semester, $type);

        $this->deleteJson("/api/v1/event-types/{$type->id}")->assertStatus(422);
    }

    // =================================================================
    // Event akademik — CRUD
    // =================================================================

    public function test_can_create_academic_event(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType();

        $this->postJson('/api/v1/academic-events', $this->eventPayload($semester, $type, [
            'academic_year_id' => $year->id,
            'title' => 'Penilaian Tengah Semester',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Penilaian Tengah Semester')
            ->assertJsonPath('data.semester_id', $semester->id);

        $this->assertDatabaseHas('academic_events', [
            'semester_id' => $semester->id,
            'title' => 'Penilaian Tengah Semester',
        ]);
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType();

        $this->postJson('/api/v1/academic-events', $this->eventPayload($semester, $type, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-08',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_semester_must_belong_to_given_academic_year(): void
    {
        $this->actingAsRole('admin-sekolah');
        $yearA = $this->makeYear('2026/2027');
        $yearB = $this->makeYear('2027/2028');
        $semester = $this->makeSemester($yearA);
        $type = $this->makeEventType();

        $this->postJson('/api/v1/academic-events', $this->eventPayload($semester, $type, [
            'academic_year_id' => $yearB->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('semester_id');
    }

    public function test_holiday_event_cannot_overlap_other_holiday(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeHolidayType();

        $this->makeEvent($semester, $type, [
            'title' => 'Libur Awal',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-10',
            'is_holiday' => true,
        ]);

        $this->postJson('/api/v1/academic-events', $this->eventPayload($semester, $type, [
            'title' => 'Libur Bentrok',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-12',
            'is_holiday' => true,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }

    public function test_non_holiday_event_may_overlap_holiday(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $holidayType = $this->makeHolidayType();
        $activityType = $this->makeEventType();

        $this->makeEvent($semester, $holidayType, [
            'title' => 'Libur Awal',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-10',
            'is_holiday' => true,
        ]);

        $this->postJson('/api/v1/academic-events', $this->eventPayload($semester, $activityType, [
            'title' => 'Kegiatan Saat Libur',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'is_holiday' => false,
        ]))->assertCreated();
    }

    public function test_can_update_academic_event(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType();
        $event = $this->makeEvent($semester, $type);

        $this->putJson("/api/v1/academic-events/{$event->id}", $this->eventPayload($semester, $type, [
            'title' => 'Judul Baru',
            'start_date' => '2026-07-11',
            'end_date' => '2026-07-12',
        ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Judul Baru');

        $fresh = $event->fresh();
        $this->assertSame('Judul Baru', $fresh->title);
        $this->assertSame('2026-07-11', $fresh->start_date->toDateString());
        $this->assertSame('2026-07-12', $fresh->end_date->toDateString());
    }

    public function test_can_delete_academic_event(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType();
        $event = $this->makeEvent($semester, $type);

        $this->deleteJson("/api/v1/academic-events/{$event->id}")->assertOk();

        $this->assertSoftDeleted('academic_events', ['id' => $event->id]);
    }

    // =================================================================
    // Event akademik — filter & pencarian
    // =================================================================

    public function test_events_can_be_filtered_by_semester(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semesterA = $this->makeSemester($year, 'Ganjil');
        $yearB = $this->makeYear('2027/2028');
        $semesterB = $this->makeSemester($yearB, 'Ganjil');
        $type = $this->makeEventType();

        $this->makeEvent($semesterA, $type, ['title' => 'Event A']);
        $this->makeEvent($semesterB, $type, ['title' => 'Event B']);

        $this->getJson("/api/v1/academic-events?filter[semester_id]={$semesterA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Event A');
    }

    public function test_events_can_be_filtered_by_event_type(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $typeA = $this->makeEventType(['code' => 'UTS', 'name' => 'UTS']);
        $typeB = $this->makeEventType(['code' => 'RAPAT_GURU', 'name' => 'Rapat']);

        $this->makeEvent($semester, $typeA, ['title' => 'Ujian']);
        $this->makeEvent($semester, $typeB, ['title' => 'Rapat']);

        $this->getJson("/api/v1/academic-events?filter[event_type_id]={$typeA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ujian');
    }

    public function test_events_can_be_filtered_by_holiday_flag(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $holidayType = $this->makeHolidayType();
        $activityType = $this->makeEventType();

        $this->makeEvent($semester, $holidayType, [
            'title' => 'Libur',
            'is_holiday' => true,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-06',
        ]);
        $this->makeEvent($semester, $activityType, ['title' => 'Kegiatan']);

        $this->getJson('/api/v1/academic-events?filter[is_holiday]=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Libur');
    }

    public function test_events_can_be_searched_by_title(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType();

        $this->makeEvent($semester, $type, ['title' => 'Libur Idul Fitri']);
        $this->makeEvent($semester, $type, ['title' => 'Rapat Guru']);

        $this->getJson('/api/v1/academic-events?search=Idul')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Libur Idul Fitri');
    }

    public function test_events_can_be_filtered_by_date_range(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year, 'Ganjil', '2026-07-06', '2026-07-31');
        $type = $this->makeEventType();

        $this->makeEvent($semester, $type, [
            'title' => 'Awal',
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-08',
        ]);
        $this->makeEvent($semester, $type, [
            'title' => 'Akhir',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
        ]);

        $this->getJson('/api/v1/academic-events?from=2026-07-15&to=2026-07-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Akhir');
    }

    // =================================================================
    // Kalender per bulan
    // =================================================================

    public function test_calendar_groups_events_by_month(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year, 'Ganjil', '2026-07-06', '2026-08-31');
        $type = $this->makeEventType();

        $this->makeEvent($semester, $type, [
            'title' => 'Event Agustus',
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-05',
        ]);

        $response = $this->getJson("/api/v1/academic-calendar?semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.months')
            ->assertJsonPath('data.months.0.month', '2026-07')
            ->assertJsonPath('data.months.0.label', 'Juli 2026')
            ->assertJsonCount(0, 'data.months.0.events')
            ->assertJsonPath('data.months.1.month', '2026-08')
            ->assertJsonPath('data.months.1.label', 'Agustus 2026')
            ->assertJsonCount(1, 'data.months.1.events')
            ->assertJsonPath('data.months.1.events.0.title', 'Event Agustus');

        $response->assertJsonPath('meta.total', 1);
    }

    public function test_calendar_meta_total_counts_all_events(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $type = $this->makeEventType();

        $this->makeEvent($semester, $type, ['title' => 'Satu']);
        $this->makeEvent($semester, $type, ['title' => 'Dua', 'start_date' => '2026-07-15', 'end_date' => '2026-07-15']);

        $this->getJson("/api/v1/academic-calendar?semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    // =================================================================
    // Hari efektif
    // =================================================================

    public function test_can_create_effective_day_manually(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);

        $this->postJson('/api/v1/effective-days', [
            'semester_id' => $semester->id,
            'date' => '2026-07-20',
            'is_school_day' => true,
            'note' => 'Hari pertama',
        ])
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-07-20')
            ->assertJsonPath('data.is_school_day', true);

        $this->assertTrue(
            EffectiveDay::where('semester_id', $semester->id)
                ->whereDate('date', '2026-07-20')
                ->where('is_school_day', true)
                ->exists()
        );
    }

    public function test_duplicate_effective_day_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);

        EffectiveDay::create([
            'semester_id' => $semester->id,
            'education_level_id' => null,
            'date' => '2026-07-20',
            'is_school_day' => true,
        ]);

        $this->postJson('/api/v1/effective-days', [
            'semester_id' => $semester->id,
            'date' => '2026-07-20',
            'is_school_day' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    public function test_same_date_different_education_level_is_allowed(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $level = $this->makeLevel();

        EffectiveDay::create([
            'semester_id' => $semester->id,
            'education_level_id' => null,
            'date' => '2026-07-20',
            'is_school_day' => true,
        ]);

        $this->postJson('/api/v1/effective-days', [
            'semester_id' => $semester->id,
            'education_level_id' => $level->id,
            'date' => '2026-07-20',
            'is_school_day' => true,
        ])->assertCreated();
    }

    public function test_generate_excludes_sundays_and_holidays(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $holidayType = $this->makeHolidayType();

        // 2026-07-08 (Rabu) libur nasional.
        $this->makeEvent($semester, $holidayType, [
            'title' => 'Libur Nasional',
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-08',
            'is_holiday' => true,
        ]);

        $this->postJson('/api/v1/effective-days/generate', ['semester_id' => $semester->id])
            ->assertOk()
            ->assertJsonPath('data.created', 14)
            ->assertJsonPath('data.school_days', 11)
            ->assertJsonPath('data.holidays', 3);

        // Minggu bukan hari sekolah.
        $this->assertFalse($this->isSchoolDay($semester, '2026-07-12'));
        // Senin tetap hari sekolah.
        $this->assertTrue($this->isSchoolDay($semester, '2026-07-13'));
        // Tanggal libur bukan hari sekolah.
        $this->assertFalse($this->isSchoolDay($semester, '2026-07-08'));
    }

    public function test_generate_skips_existing_dates_without_overwrite(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);

        $this->postJson('/api/v1/effective-days/generate', ['semester_id' => $semester->id])->assertOk();

        $this->postJson('/api/v1/effective-days/generate', ['semester_id' => $semester->id])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 14);

        $this->assertSame(14, EffectiveDay::where('semester_id', $semester->id)->count());
    }

    public function test_generate_overwrite_updates_existing_dates(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);

        $this->postJson('/api/v1/effective-days/generate', ['semester_id' => $semester->id])->assertOk();

        // Tambah libur baru setelah generate pertama.
        $holidayType = $this->makeHolidayType();
        $this->makeEvent($semester, $holidayType, [
            'title' => 'Libur Tambahan',
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'is_holiday' => true,
        ]);

        $this->postJson('/api/v1/effective-days/generate', [
            'semester_id' => $semester->id,
            'overwrite' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 14);

        $this->assertFalse($this->isSchoolDay($semester, '2026-07-09'));
    }

    public function test_effective_days_summary(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);
        $holidayType = $this->makeHolidayType();

        $this->makeEvent($semester, $holidayType, [
            'title' => 'Libur Nasional',
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-08',
            'is_holiday' => true,
        ]);

        $this->postJson('/api/v1/effective-days/generate', ['semester_id' => $semester->id])->assertOk();

        $this->getJson("/api/v1/effective-days/summary?semester_id={$semester->id}")
            ->assertOk()
            ->assertJsonPath('data.total_school_days', 11)
            ->assertJsonPath('data.total_holidays', 3)
            ->assertJsonPath('data.total_effective_days', 11)
            ->assertJsonCount(1, 'data.months')
            ->assertJsonPath('data.months.0.month', '2026-07')
            ->assertJsonPath('data.months.0.school_days', 11)
            ->assertJsonPath('data.months.0.holidays', 3);
    }

    public function test_can_update_and_delete_effective_day(): void
    {
        $this->actingAsRole('admin-sekolah');
        $year = $this->makeYear();
        $semester = $this->makeSemester($year);

        $day = EffectiveDay::create([
            'semester_id' => $semester->id,
            'education_level_id' => null,
            'date' => '2026-07-20',
            'is_school_day' => true,
        ]);

        $this->putJson("/api/v1/effective-days/{$day->id}", [
            'semester_id' => $semester->id,
            'date' => '2026-07-20',
            'is_school_day' => false,
            'note' => 'Dibuat libur',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_school_day', false);

        $this->deleteJson("/api/v1/effective-days/{$day->id}")->assertOk();
        $this->assertDatabaseMissing('effective_days', ['id' => $day->id]);
    }

    // =================================================================
    // RBAC
    // =================================================================

    public function test_guru_can_view_but_cannot_create(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/event-types')->assertOk();
        $this->getJson('/api/v1/academic-events')->assertOk();

        $this->postJson('/api/v1/event-types', [
            'code' => 'TERLARANG',
            'name' => 'Tidak Boleh',
        ])->assertForbidden();
    }

    public function test_guest_cannot_access_calendar(): void
    {
        $this->getJson('/api/v1/academic-events')->assertUnauthorized();
        $this->getJson('/api/v1/event-types')->assertUnauthorized();
    }
}
