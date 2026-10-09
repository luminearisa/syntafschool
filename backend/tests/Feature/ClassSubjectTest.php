<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassSubjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

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

    private function makeClass(string $code = 'X-IPA-1', string $name = 'X IPA 1'): SchoolClass
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );

        return SchoolClass::create([
            'academic_year_id' => $year->id,
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

    private function makeEmployee(bool $isTeacher = true, string $name = 'Budi Santoso'): Employee
    {
        return Employee::create([
            'full_name' => $name,
            'employee_type' => $isTeacher ? 'guru' : 'staf',
            'employment_status' => 'PNS',
            'is_teacher' => $isTeacher,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_list_class_subjects(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
        ]);

        $this->getJson("/api/v1/classes/{$class->id}/subjects")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.code', 'MTK');
    }

    public function test_super_admin_can_add_subject_with_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeEmployee();

        $this->postJson("/api/v1/classes/{$class->id}/subjects", [
            'subjects' => [
                ['subject_id' => $subject->id, 'employee_id' => $teacher->id],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseHas('class_subjects', [
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'employee_id' => $teacher->id,
        ]);
    }

    public function test_duplicate_subject_is_skipped_not_failed(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $payload = ['subjects' => [['subject_id' => $subject->id]]];

        $this->postJson("/api/v1/classes/{$class->id}/subjects", $payload)->assertCreated();

        // Penambahan ulang harus aman diulang: 0 ditambah, 1 dilewati.
        $this->postJson("/api/v1/classes/{$class->id}/subjects", $payload)
            ->assertCreated()
            ->assertJsonPath('data.added', 0)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, ClassSubject::where('school_class_id', $class->id)->count());
    }

    public function test_teacher_must_be_flagged_as_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $staff = $this->makeEmployee(false, 'Staf TU');

        $this->postJson("/api/v1/classes/{$class->id}/subjects", [
            'subjects' => [
                ['subject_id' => $subject->id, 'employee_id' => $staff->id],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subjects');

        $this->assertDatabaseCount('class_subjects', 0);
    }

    public function test_super_admin_can_change_teacher(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $oldTeacher = $this->makeEmployee(true, 'Guru Lama');
        $newTeacher = $this->makeEmployee(true, 'Guru Baru');

        $cs = ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'employee_id' => $oldTeacher->id,
            'academic_year_id' => $class->academic_year_id,
        ]);

        $this->putJson("/api/v1/classes/{$class->id}/subjects/{$cs->id}", [
            'employee_id' => $newTeacher->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.teacher.full_name', 'Guru Baru');

        // Guru boleh dikosongkan (mapel tanpa pengampu).
        $this->putJson("/api/v1/classes/{$class->id}/subjects/{$cs->id}", [
            'employee_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.employee_id', null);
    }

    public function test_super_admin_can_remove_subject_from_class(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $cs = ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
        ]);

        $this->deleteJson("/api/v1/classes/{$class->id}/subjects/{$cs->id}")->assertOk();

        $this->assertDatabaseCount('class_subjects', 0);
    }

    public function test_subject_from_another_class_returns_404(): void
    {
        $this->actingAsRole('super-admin');
        $classA = $this->makeClass('X-IPA-1', 'X IPA 1');
        $classB = $this->makeClass('X-IPA-2', 'X IPA 2');
        $subject = $this->makeSubject();

        $cs = ClassSubject::create([
            'school_class_id' => $classA->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $classA->academic_year_id,
        ]);

        $this->deleteJson("/api/v1/classes/{$classB->id}/subjects/{$cs->id}")->assertNotFound();

        $this->assertDatabaseCount('class_subjects', 1);
    }

    public function test_super_admin_can_copy_subjects_from_another_class(): void
    {
        $this->actingAsRole('super-admin');
        $source = $this->makeClass('X-IPA-1', 'X IPA 1');
        $target = $this->makeClass('X-IPA-2', 'X IPA 2');
        $math = $this->makeSubject('MTK', 'Matematika');
        $fisika = $this->makeSubject('FIS', 'Fisika');

        foreach ([$math, $fisika] as $subject) {
            ClassSubject::create([
                'school_class_id' => $source->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $source->academic_year_id,
            ]);
        }

        // Target sudah punya Matematika → hanya Fisika yang disalin.
        ClassSubject::create([
            'school_class_id' => $target->id,
            'subject_id' => $math->id,
            'academic_year_id' => $target->academic_year_id,
        ]);

        $this->postJson("/api/v1/classes/{$target->id}/subjects/copy", [
            'source_class_id' => $source->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(2, ClassSubject::where('school_class_id', $target->id)->count());
    }

    public function test_copy_from_same_class_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $class = $this->makeClass();
        $this->makeSubject();

        $this->postJson("/api/v1/classes/{$class->id}/subjects/copy", [
            'source_class_id' => $class->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_class_id');
    }

    public function test_copy_from_empty_class_is_rejected(): void
    {
        $this->actingAsRole('super-admin');
        $source = $this->makeClass('X-IPA-1', 'X IPA 1');
        $target = $this->makeClass('X-IPA-2', 'X IPA 2');

        $this->postJson("/api/v1/classes/{$target->id}/subjects/copy", [
            'source_class_id' => $source->id,
        ])->assertStatus(422);
    }

    public function test_guru_can_view_but_not_modify_class_subjects(): void
    {
        $this->actingAsRole('guru');
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $this->getJson("/api/v1/classes/{$class->id}/subjects")->assertOk();

        $this->postJson("/api/v1/classes/{$class->id}/subjects", [
            'subjects' => [['subject_id' => $subject->id]],
        ])->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $class = $this->makeClass();

        $this->getJson("/api/v1/classes/{$class->id}/subjects")->assertUnauthorized();
    }
}
