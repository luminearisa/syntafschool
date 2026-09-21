<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\Gender;
use App\Models\Guardian;
use App\Models\GuardianRelation;
use App\Models\Religion;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\StudentStatus;
use App\Models\User;
use App\Models\Village;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A3 — Data Siswa & Wali Murid.
 *
 * Aturan yang diuji secara khusus:
 * - NIS/NISN unik
 * - rantai wilayah berjenjang harus konsisten
 * - satu siswa hanya boleh punya satu wali utama
 * - riwayat kelas menyinkronkan keanggotaan rombel
 */
class StudentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->seed(RegionSeeder::class);
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function actingAsRole(string $role = 'admin-sekolah'): User
    {
        $user = User::factory()->create([
            'email' => "{$role}@syntaf.test",
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeYear(): AcademicYear
    {
        return AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );
    }

    private function makeClass(?AcademicYear $year = null, string $code = 'X-IPA-1', string $name = 'X IPA 1'): SchoolClass
    {
        $year ??= $this->makeYear();

        return SchoolClass::create([
            'academic_year_id' => $year->id,
            'name' => $name,
            'code' => $code,
            'capacity' => 32,
            'is_active' => true,
        ]);
    }

    /**
     * Muat kode wilayah lengkap untuk mengisi rantai berjenjang.
     *
     * @return array<string, string>
     */
    private function region(): array
    {
        $village = Village::with('district.city')->first();
        $city = $village->district->city;

        return [
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'district_id' => $village->district_id,
            'village_id' => $village->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function studentPayload(array $overrides = []): array
    {
        return array_merge([
            'nis' => '20250001',
            'nisn' => '0012345678',
            'full_name' => 'Adi Nugroho',
            'nick_name' => 'Adi',
            'gender_id' => Gender::where('code', 'L')->value('id'),
            'religion_id' => Religion::where('name', 'Islam')->value('id'),
            'birth_place' => 'Yogyakarta',
            'birth_date' => '2009-03-12',
            'blood_type' => 'O',
            'address' => 'Jl. Pendidikan No. 1',
            'status_id' => StudentStatus::where('name', 'Aktif')->value('id'),
            'is_active' => true,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // CRUD siswa
    // -----------------------------------------------------------------

    public function test_admin_can_list_students(): void
    {
        $this->actingAsRole();
        Student::create($this->studentPayload());

        $response = $this->getJson('/api/v1/students');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.full_name', 'Adi Nugroho')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_student_can_be_created(): void
    {
        $this->actingAsRole();

        $response = $this->postJson('/api/v1/students', $this->studentPayload());

        $response->assertCreated()
            ->assertJsonPath('data.nis', '20250001')
            ->assertJsonPath('data.full_name', 'Adi Nugroho');

        $this->assertDatabaseHas('students', ['nis' => '20250001']);
    }

    public function test_nis_must_be_unique(): void
    {
        $this->actingAsRole();
        Student::create($this->studentPayload());

        $response = $this->postJson('/api/v1/students', $this->studentPayload([
            'nisn' => '0099999999',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('nis');
    }

    public function test_nisn_must_be_unique(): void
    {
        $this->actingAsRole();
        Student::create($this->studentPayload());

        $response = $this->postJson('/api/v1/students', $this->studentPayload([
            'nis' => '20250002',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('nisn');
    }

    public function test_student_can_be_updated(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $response = $this->putJson("/api/v1/students/{$student->id}", $this->studentPayload([
            'full_name' => 'Adi Nugroho Wijaya',
            'nick_name' => 'Adi Wijaya',
        ]));

        $response->assertOk()->assertJsonPath('data.full_name', 'Adi Nugroho Wijaya');
        $this->assertDatabaseHas('students', ['id' => $student->id, 'full_name' => 'Adi Nugroho Wijaya']);
    }

    public function test_student_can_be_deleted(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $this->deleteJson("/api/v1/students/{$student->id}")->assertOk();

        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_delete_is_blocked_when_enrolled_in_active_academic_year(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        ClassStudent::create([
            'student_id' => $student->id,
            'school_class_id' => $class->id,
            'academic_year_id' => $year->id,
            'status' => 'aktif',
        ]);

        $this->deleteJson("/api/v1/students/{$student->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    }

    public function test_student_can_be_toggled_active(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload(['is_active' => true]));

        $this->patchJson("/api/v1/students/{$student->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    // -----------------------------------------------------------------
    // Wilayah berjenjang
    // -----------------------------------------------------------------

    public function test_postal_code_is_filled_from_selected_village(): void
    {
        $this->actingAsRole();
        $region = $this->region();
        $expected = Village::whereKey($region['village_id'])->value('postal_code');

        $response = $this->postJson('/api/v1/students', $this->studentPayload($region));

        $response->assertCreated();
        $this->assertSame($expected, $response->json('data.postal_code'));
    }

    public function test_region_chain_must_be_consistent(): void
    {
        $this->actingAsRole();
        $region = $this->region();

        // Provinsi sengaja ditukar dengan provinsi lain.
        $otherProvince = \App\Models\Province::where('id', '!=', $region['province_id'])->value('id');

        $response = $this->postJson('/api/v1/students', $this->studentPayload([
            ...$region,
            'province_id' => $otherProvince,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('city_id');
    }

    // -----------------------------------------------------------------
    // Wali murid
    // -----------------------------------------------------------------

    public function test_guardian_can_be_created_inline_with_student(): void
    {
        $this->actingAsRole();
        $ayah = GuardianRelation::where('name', 'Ayah')->value('id');

        $response = $this->postJson('/api/v1/students', $this->studentPayload([
            'guardians' => [
                ['full_name' => 'Bapak Santoso', 'guardian_relation_id' => $ayah, 'is_primary' => true],
            ],
        ]));

        $response->assertCreated()->assertJsonPath('data.guardians.0.full_name', 'Bapak Santoso');

        $this->assertDatabaseHas('guardians', ['full_name' => 'Bapak Santoso']);
        $this->assertDatabaseHas('student_guardians', ['is_primary' => true]);
    }

    public function test_first_guardian_becomes_primary_when_none_flagged(): void
    {
        $this->actingAsRole();

        $response = $this->postJson('/api/v1/students', $this->studentPayload([
            'guardians' => [
                ['full_name' => 'Bapak Santoso'],
                ['full_name' => 'Ibu Santoso'],
            ],
        ]));

        $response->assertCreated();

        $primary = collect($response->json('data.guardians'))->firstWhere('is_primary', true);
        $this->assertSame('Bapak Santoso', $primary['full_name']);
    }

    public function test_payload_with_two_primary_guardians_is_rejected(): void
    {
        $this->actingAsRole();

        $response = $this->postJson('/api/v1/students', $this->studentPayload([
            'guardians' => [
                ['full_name' => 'Bapak Santoso', 'is_primary' => true],
                ['full_name' => 'Ibu Santoso', 'is_primary' => true],
            ],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('guardians');
        $this->assertDatabaseMissing('students', ['nis' => '20250001']);
    }

    public function test_attaching_primary_guardian_demotes_the_previous_one(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $first = $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'full_name' => 'Bapak Santoso',
            'is_primary' => true,
        ])->assertCreated();

        $firstGuardianId = $first->json('data.guardians.0.id');

        $second = $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'full_name' => 'Ibu Santoso',
            'is_primary' => true,
        ])->assertCreated();

        $guardians = collect($second->json('data.guardians'));

        $this->assertSame(1, $guardians->where('is_primary', true)->count());
        $this->assertFalse($guardians->firstWhere('id', $firstGuardianId)['is_primary']);
    }

    public function test_detaching_primary_guardian_promotes_the_next_one(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'full_name' => 'Bapak Santoso',
            'is_primary' => true,
        ])->assertCreated();

        $second = $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'full_name' => 'Ibu Santoso',
        ])->assertCreated();

        $primaryId = $second->json('data.guardians.0.id');
        $otherId = $second->json('data.guardians.1.id');

        $response = $this->deleteJson("/api/v1/students/{$student->id}/guardians/{$primaryId}")
            ->assertOk();

        $remaining = collect($response->json('data.guardians'));
        $this->assertSame(1, $remaining->count());
        $this->assertTrue($remaining->firstWhere('id', $otherId)['is_primary']);
    }

    public function test_duplicate_guardian_attachment_is_rejected(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());
        $guardian = Guardian::create(['full_name' => 'Bapak Santoso']);

        $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'guardian_id' => $guardian->id,
        ])->assertCreated();

        $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'guardian_id' => $guardian->id,
        ])->assertStatus(422)->assertJsonValidationErrors('guardian_id');
    }

    public function test_attach_requires_existing_id_or_new_name(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/guardians", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guardian_id');
    }

    public function test_guardian_relation_can_be_updated(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());
        $guardian = Guardian::create(['full_name' => 'Bapak Santoso']);

        $this->postJson("/api/v1/students/{$student->id}/guardians", [
            'guardian_id' => $guardian->id,
            'guardian_relation_id' => GuardianRelation::where('name', 'Ayah')->value('id'),
        ])->assertCreated();

        $waliId = GuardianRelation::where('name', 'Wali')->value('id');

        $response = $this->patchJson("/api/v1/students/{$student->id}/guardians/{$guardian->id}", [
            'guardian_relation_id' => $waliId,
            'is_primary' => true,
        ]);

        $response->assertOk();
        $this->assertSame(
            $waliId,
            $response->json('data.guardians.0.guardian_relation_id'),
        );
    }

    public function test_siblings_can_share_the_same_guardian(): void
    {
        $this->actingAsRole();
        $guardian = Guardian::create(['full_name' => 'Bapak Santoso']);

        $first = Student::create($this->studentPayload());
        $second = Student::create($this->studentPayload(['nis' => '20250002', 'nisn' => '0012345679']));

        $this->postJson("/api/v1/students/{$first->id}/guardians", ['guardian_id' => $guardian->id])->assertCreated();
        $this->postJson("/api/v1/students/{$second->id}/guardians", ['guardian_id' => $guardian->id])->assertCreated();

        $this->assertSame(2, $guardian->students()->count());
    }

    // -----------------------------------------------------------------
    // CRUD wali mandiri
    // -----------------------------------------------------------------

    public function test_guardian_can_be_created_and_listed(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/v1/guardians', ['full_name' => 'Bapak Santoso'])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Bapak Santoso');

        $this->getJson('/api/v1/guardians')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_guardian_delete_is_blocked_when_still_linked(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());
        $guardian = Guardian::create(['full_name' => 'Bapak Santoso']);
        $student->guardians()->attach($guardian->id, ['is_primary' => true]);

        $this->deleteJson("/api/v1/guardians/{$guardian->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'deleted_at' => null]);
    }

    public function test_guardian_can_be_deleted_when_unlinked(): void
    {
        $this->actingAsRole();
        $guardian = Guardian::create(['full_name' => 'Bapak Santoso']);

        $this->deleteJson("/api/v1/guardians/{$guardian->id}")->assertOk();
        $this->assertSoftDeleted('guardians', ['id' => $guardian->id]);
    }

    // -----------------------------------------------------------------
    // Riwayat kelas
    // -----------------------------------------------------------------

    public function test_history_creation_syncs_class_membership(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/histories", [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'baru',
        ])->assertCreated()->assertJsonPath('data.status', 'baru');

        $this->assertDatabaseHas('student_histories', [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'baru',
        ]);

        $this->assertDatabaseHas('class_students', [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'aktif',
        ]);
    }

    public function test_history_status_keluar_marks_class_membership_as_keluar(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/histories", [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'keluar',
        ])->assertCreated();

        $this->assertDatabaseHas('class_students', [
            'student_id' => $student->id,
            'status' => 'keluar',
        ]);
    }

    public function test_history_class_must_belong_to_the_selected_academic_year(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $otherYear = AcademicYear::create([
            'name' => '2024/2025',
            'start_date' => '2024-07-01',
            'end_date' => '2025-06-30',
            'is_active' => false,
        ]);
        $class = $this->makeClass($otherYear, 'X-IPA-9', 'X IPA 9');
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/histories", [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'baru',
        ])->assertStatus(422)->assertJsonValidationErrors('school_class_id');
    }

    public function test_history_is_updated_when_same_year_is_submitted_twice(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        $payload = [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'baru',
        ];

        $this->postJson("/api/v1/students/{$student->id}/histories", $payload)->assertCreated();
        $this->postJson("/api/v1/students/{$student->id}/histories", [...$payload, 'status' => 'tinggal'])->assertCreated();

        $this->assertSame(1, StudentHistory::where('student_id', $student->id)->count());
        $this->assertDatabaseHas('student_histories', ['student_id' => $student->id, 'status' => 'tinggal']);
    }

    public function test_history_can_be_listed_and_deleted(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        $created = $this->postJson("/api/v1/students/{$student->id}/histories", [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'baru',
        ])->assertCreated();

        $historyId = $created->json('data.id');

        $this->getJson("/api/v1/students/{$student->id}/histories")
            ->assertOk()
            ->assertJsonPath('data.0.class_name', 'X IPA 1');

        $this->deleteJson("/api/v1/students/{$student->id}/histories/{$historyId}")->assertOk();
        $this->assertDatabaseMissing('student_histories', ['id' => $historyId]);
    }

    public function test_deleting_history_also_removes_class_membership(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        $created = $this->postJson("/api/v1/students/{$student->id}/histories", [
            'academic_year_id' => $year->id,
            'school_class_id' => $class->id,
            'status' => 'baru',
        ])->assertCreated();

        $this->assertDatabaseHas('class_students', ['student_id' => $student->id]);

        $this->deleteJson("/api/v1/students/{$student->id}/histories/{$created->json('data.id')}")
            ->assertOk();

        // Keanggotaan rombel harus ikut terhapus agar tidak menjadi data yatim.
        $this->assertDatabaseMissing('class_students', ['student_id' => $student->id]);

        // Setelah itu siswa bisa dihapus.
        $this->deleteJson("/api/v1/students/{$student->id}")->assertOk();
        $this->assertSoftDeleted('students', ['id' => $student->id]);
    }

    public function test_deleting_history_without_class_keeps_membership(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);
        $student = Student::create($this->studentPayload());

        ClassStudent::create([
            'student_id' => $student->id,
            'school_class_id' => $class->id,
            'academic_year_id' => $year->id,
            'status' => 'aktif',
        ]);

        $created = $this->postJson("/api/v1/students/{$student->id}/histories", [
            'academic_year_id' => $year->id,
            'status' => 'naik',
        ])->assertCreated();

        $this->deleteJson("/api/v1/students/{$student->id}/histories/{$created->json('data.id')}")
            ->assertOk();

        // Riwayat tanpa kelas tidak menyentuh keanggotaan rombel.
        $this->assertDatabaseHas('class_students', ['student_id' => $student->id]);
    }

    // -----------------------------------------------------------------
    // Akun portal
    // -----------------------------------------------------------------

    public function test_portal_account_can_be_created(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/account", [
            'email' => 'adi@siswa.syntaf.test',
            'password' => 'rahasia123',
        ])->assertCreated()->assertJsonPath('data.email', 'adi@siswa.syntaf.test');

        $this->assertDatabaseHas('users', [
            'email' => 'adi@siswa.syntaf.test',
            'userable_type' => Student::class,
            'userable_id' => $student->id,
        ]);
    }

    public function test_second_portal_account_is_rejected(): void
    {
        $this->actingAsRole();
        $student = Student::create($this->studentPayload());

        $this->postJson("/api/v1/students/{$student->id}/account", [
            'email' => 'adi@siswa.syntaf.test',
            'password' => 'rahasia123',
        ])->assertCreated();

        $this->postJson("/api/v1/students/{$student->id}/account", [
            'email' => 'adi2@siswa.syntaf.test',
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------
    // Filter & RBAC
    // -----------------------------------------------------------------

    public function test_students_can_be_filtered_by_class(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $classA = $this->makeClass($year, 'X-IPA-1', 'X IPA 1');
        $classB = $this->makeClass($year, 'X-IPA-2', 'X IPA 2');

        $inA = Student::create($this->studentPayload());
        $inB = Student::create($this->studentPayload(['nis' => '20250002', 'nisn' => '0012345679']));

        ClassStudent::create(['student_id' => $inA->id, 'school_class_id' => $classA->id, 'academic_year_id' => $year->id, 'status' => 'aktif']);
        ClassStudent::create(['student_id' => $inB->id, 'school_class_id' => $classB->id, 'academic_year_id' => $year->id, 'status' => 'aktif']);

        $response = $this->getJson("/api/v1/students?class_id={$classA->id}");

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('Adi Nugroho', $response->json('data.0.full_name'));
    }

    public function test_unassigned_filter_returns_students_without_class(): void
    {
        $this->actingAsRole();
        $year = $this->makeYear();
        $class = $this->makeClass($year);

        $assigned = Student::create($this->studentPayload());
        $free = Student::create($this->studentPayload(['nis' => '20250002', 'nisn' => '0012345679']));

        ClassStudent::create(['student_id' => $assigned->id, 'school_class_id' => $class->id, 'academic_year_id' => $year->id, 'status' => 'aktif']);

        $response = $this->getJson('/api/v1/students?unassigned=1');

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($free->id, $response->json('data.0.id'));
    }

    public function test_search_matches_name_nis_and_nisn(): void
    {
        $this->actingAsRole();
        Student::create($this->studentPayload());
        Student::create($this->studentPayload(['nis' => '20250002', 'nisn' => '0099999999', 'full_name' => 'Bella Safira']));

        $this->getJson('/api/v1/students?search=Bella')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/students?search=20250002')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/students?search=0099999999')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_teacher_cannot_create_student(): void
    {
        $this->actingAsRole('guru');

        $this->postJson('/api/v1/students', $this->studentPayload())
            ->assertStatus(403);
    }

    public function test_teacher_can_view_but_not_delete_student(): void
    {
        $this->actingAsRole('guru');
        $student = Student::create($this->studentPayload());

        $this->getJson('/api/v1/students')->assertOk();
        $this->deleteJson("/api/v1/students/{$student->id}")->assertStatus(403);
    }

    public function test_guest_cannot_access_students(): void
    {
        $this->getJson('/api/v1/students')->assertStatus(401);
    }
}
