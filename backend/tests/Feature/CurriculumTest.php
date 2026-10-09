<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumStructure;
use App\Models\EducationLevel;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A5 — Kurikulum Engine.
 *
 * Setiap aturan bisnis punya test tersendiri: versi unik, mapel dobel dalam
 * satu semester, rentang JP & semester, prasyarat diri sendiri & siklus,
 * duplikasi struktur, penerapan ke kelas (idempoten), kunci hapus struktur
 * yang sudah dipakai, kurikulum per kelas, dan RBAC.
 */
class CurriculumTest extends TestCase
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

    private function makeCurriculum(string $code = 'MERDEKA', bool $active = true): Curriculum
    {
        return Curriculum::create([
            'name' => "Kurikulum {$code}",
            'code' => $code,
            'is_active' => $active,
        ]);
    }

    private function makeLevel(string $name = 'Kelas 10', int $number = 10): EducationLevel
    {
        return EducationLevel::create([
            'name' => $name,
            'level_number' => $number,
            'jenjang' => 'SMA',
            'is_active' => true,
        ]);
    }

    private function makeYear(string $name = '2025/2026'): AcademicYear
    {
        return AcademicYear::create([
            'name' => $name,
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
        ]);
    }

    private function makeSubject(string $code, string $name = 'Mapel'): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'category' => 'Wajib',
            'credit' => 2,
            'is_active' => true,
        ]);
    }

    private function makeGroup(string $code = 'WAJIB'): SubjectGroup
    {
        return SubjectGroup::create([
            'code' => $code,
            'name' => "Kelompok {$code}",
            'is_active' => true,
        ]);
    }

    private function makeClass(EducationLevel $level, AcademicYear $year, string $code = 'X-IPA-1'): SchoolClass
    {
        return SchoolClass::create([
            'academic_year_id' => $year->id,
            'education_level_id' => $level->id,
            'name' => "Kelas {$code}",
            'code' => $code,
            'capacity' => 32,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function structurePayload(
        Curriculum $curriculum,
        EducationLevel $level,
        array $items,
        int $version = 1,
        ?AcademicYear $year = null,
    ): array {
        return [
            'curriculum_id' => $curriculum->id,
            'education_level_id' => $level->id,
            'academic_year_id' => $year?->id,
            'version' => $version,
            'name' => "Struktur v{$version}",
            'is_active' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Subject $subject, int $semester = 1, int $hours = 2, ?SubjectGroup $group = null): array
    {
        return [
            'subject_id' => $subject->id,
            'subject_group_id' => $group?->id,
            'semester' => $semester,
            'weekly_hours' => $hours,
            'is_required' => true,
        ];
    }

    private function makeStructure(
        Curriculum $curriculum,
        EducationLevel $level,
        array $subjects,
        int $version = 1,
        ?AcademicYear $year = null,
    ): CurriculumStructure {
        $structure = CurriculumStructure::create([
            'curriculum_id' => $curriculum->id,
            'education_level_id' => $level->id,
            'academic_year_id' => $year?->id,
            'version' => $version,
            'name' => "Struktur v{$version}",
            'is_active' => true,
        ]);

        foreach ($subjects as $index => $subject) {
            $structure->items()->create([
                'subject_id' => $subject->id,
                'semester' => 1,
                'weekly_hours' => 2,
                'is_required' => true,
                'sort_order' => $index,
            ]);
        }

        return $structure;
    }

    // =================================================================
    // Kelompok mapel — CRUD
    // =================================================================

    public function test_can_list_subject_groups(): void
    {
        $this->actingAsRole('admin-sekolah');
        $this->makeGroup('WAJIB');
        $this->makeGroup('MIPA');

        $this->getJson('/api/v1/subject-groups')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_can_create_update_and_delete_subject_group(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/subject-groups', [
            'code' => 'MULOK',
            'name' => 'Muatan Lokal',
            'sort_order' => 5,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MULOK');

        $id = $created->json('data.id');

        $this->putJson("/api/v1/subject-groups/{$id}", [
            'code' => 'MULOK',
            'name' => 'Muatan Lokal Daerah',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Muatan Lokal Daerah');

        $this->deleteJson("/api/v1/subject-groups/{$id}")->assertOk();
        $this->assertSoftDeleted('subject_groups', ['id' => $id]);
    }

    public function test_subject_group_code_must_be_unique(): void
    {
        $this->actingAsRole('admin-sekolah');
        $this->makeGroup('WAJIB');

        $this->postJson('/api/v1/subject-groups', ['code' => 'WAJIB', 'name' => 'Duplikat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // =================================================================
    // Program / peminatan — CRUD
    // =================================================================

    public function test_can_create_update_and_delete_program(): void
    {
        $this->actingAsRole('admin-sekolah');

        $created = $this->postJson('/api/v1/programs', [
            'code' => 'IPA',
            'name' => 'Ilmu Pengetahuan Alam',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'IPA');

        $id = $created->json('data.id');

        $this->putJson("/api/v1/programs/{$id}", [
            'code' => 'IPA',
            'name' => 'Peminatan IPA',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Peminatan IPA');

        $this->deleteJson("/api/v1/programs/{$id}")->assertOk();
        $this->assertSoftDeleted('programs', ['id' => $id]);
    }

    // =================================================================
    // Prasyarat mapel — CRUD & aturan
    // =================================================================

    public function test_can_create_and_delete_subject_prerequisite(): void
    {
        $this->actingAsRole('admin-sekolah');
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $fis = $this->makeSubject('FIS', 'Fisika');

        $created = $this->postJson('/api/v1/subject-prerequisites', [
            'subject_id' => $fis->id,
            'prerequisite_subject_id' => $mtk->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject.code', 'FIS')
            ->assertJsonPath('data.prerequisite.code', 'MTK');

        $this->deleteJson("/api/v1/subject-prerequisites/{$created->json('data.id')}")->assertOk();
        $this->assertDatabaseCount('subject_prerequisites', 0);
    }

    public function test_prerequisite_cannot_reference_itself(): void
    {
        $this->actingAsRole('admin-sekolah');
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $this->postJson('/api/v1/subject-prerequisites', [
            'subject_id' => $mtk->id,
            'prerequisite_subject_id' => $mtk->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prerequisite_subject_id');
    }

    public function test_prerequisite_cycle_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $a = $this->makeSubject('MTK', 'Matematika');
        $b = $this->makeSubject('FIS', 'Fisika');

        // FIS membutuhkan MTK.
        $this->postJson('/api/v1/subject-prerequisites', [
            'subject_id' => $b->id,
            'prerequisite_subject_id' => $a->id,
        ])->assertCreated();

        // MTK membutuhkan FIS → membentuk siklus FIS → MTK → FIS.
        $this->postJson('/api/v1/subject-prerequisites', [
            'subject_id' => $a->id,
            'prerequisite_subject_id' => $b->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prerequisite_subject_id');

        $this->assertDatabaseCount('subject_prerequisites', 1);
    }

    // =================================================================
    // Struktur kurikulum — CRUD
    // =================================================================

    public function test_can_create_curriculum_structure_with_items(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $group = $this->makeGroup();
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $bind = $this->makeSubject('BIND', 'Bahasa Indonesia');

        $this->postJson('/api/v1/curriculum-structures', $this->structurePayload($curriculum, $level, [
            $this->item($mtk, 1, 4, $group),
            $this->item($bind, 1, 4, $group),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('curriculum_structure_items', ['subject_id' => $mtk->id, 'weekly_hours' => 4]);
    }

    public function test_can_update_curriculum_structure_and_replace_items(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $bind = $this->makeSubject('BIND', 'Bahasa Indonesia');

        $structure = $this->makeStructure($curriculum, $level, [$mtk]);

        $this->putJson("/api/v1/curriculum-structures/{$structure->id}", $this->structurePayload(
            $curriculum,
            $level,
            [$this->item($bind)],
        ))
            ->assertOk()
            ->assertJsonPath('data.name', 'Struktur v1')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.subject.code', 'BIND');

        $this->assertDatabaseCount('curriculum_structure_items', 1);
    }

    public function test_can_list_structures_of_a_curriculum(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $levelA = $this->makeLevel('Kelas 10', 10);
        $levelB = $this->makeLevel('Kelas 11', 11);
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $this->makeStructure($curriculum, $levelA, [$mtk]);
        $this->makeStructure($curriculum, $levelB, [$mtk]);

        $this->getJson("/api/v1/curricula/{$curriculum->id}/structures")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // =================================================================
    // Struktur kurikulum — aturan bisnis
    // =================================================================

    public function test_duplicate_version_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $this->makeStructure($curriculum, $level, [$mtk], 1);

        $this->postJson('/api/v1/curriculum-structures', $this->structurePayload(
            $curriculum,
            $level,
            [$this->item($mtk)],
            1,
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');
    }

    public function test_duplicate_subject_in_same_semester_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $this->postJson('/api/v1/curriculum-structures', $this->structurePayload($curriculum, $level, [
            $this->item($mtk, 1, 4),
            $this->item($mtk, 1, 2),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_weekly_hours_out_of_range_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $this->postJson('/api/v1/curriculum-structures', $this->structurePayload($curriculum, $level, [
            $this->item($mtk, 1, 13),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.weekly_hours');
    }

    public function test_invalid_semester_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $this->postJson('/api/v1/curriculum-structures', $this->structurePayload($curriculum, $level, [
            $this->item($mtk, 3, 2),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.semester');
    }

    public function test_duplicate_structure_copies_all_items(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $bind = $this->makeSubject('BIND', 'Bahasa Indonesia');
        $fis = $this->makeSubject('FIS', 'Fisika');

        $structure = $this->makeStructure($curriculum, $level, [$mtk, $bind, $fis], 1);

        $this->postJson("/api/v1/curriculum-structures/{$structure->id}/duplicate", [
            'version' => 2,
            'name' => 'Struktur Kurikulum 2026',
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.name', 'Struktur Kurikulum 2026')
            ->assertJsonCount(3, 'data.items');

        $this->assertDatabaseCount('curriculum_structures', 2);
        $this->assertDatabaseCount('curriculum_structure_items', 6);
    }

    public function test_duplicate_structure_with_existing_version_is_rejected(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $structure = $this->makeStructure($curriculum, $level, [$mtk], 1);
        $this->makeStructure($curriculum, $level, [$mtk], 2);

        $this->postJson("/api/v1/curriculum-structures/{$structure->id}/duplicate", ['version' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');
    }

    // =================================================================
    // Penerapan kurikulum ke kelas
    // =================================================================

    public function test_apply_curriculum_creates_class_subjects(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $year = $this->makeYear();
        $level = $this->makeLevel();
        $class = $this->makeClass($level, $year);
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $bind = $this->makeSubject('BIND', 'Bahasa Indonesia');

        $structure = $this->makeStructure($curriculum, $level, [$mtk, $bind], 1, $year);

        $this->postJson("/api/v1/classes/{$class->id}/apply-curriculum", [
            'curriculum_structure_id' => $structure->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.total', 2);

        $this->assertDatabaseCount('class_subjects', 2);
        $this->assertDatabaseHas('class_subjects', [
            'school_class_id' => $class->id,
            'subject_id' => $mtk->id,
            'curriculum_structure_id' => $structure->id,
        ]);
    }

    public function test_apply_curriculum_is_idempotent(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $year = $this->makeYear();
        $level = $this->makeLevel();
        $class = $this->makeClass($level, $year);
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $bind = $this->makeSubject('BIND', 'Bahasa Indonesia');

        $structure = $this->makeStructure($curriculum, $level, [$mtk, $bind], 1, $year);

        $payload = ['curriculum_structure_id' => $structure->id];

        $this->postJson("/api/v1/classes/{$class->id}/apply-curriculum", $payload)->assertOk();

        $this->postJson("/api/v1/classes/{$class->id}/apply-curriculum", $payload)
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 2)
            ->assertJsonPath('data.total', 2);

        $this->assertDatabaseCount('class_subjects', 2);
    }

    public function test_apply_curriculum_rejects_mismatched_education_level(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $year = $this->makeYear();
        $levelA = $this->makeLevel('Kelas 10', 10);
        $levelB = $this->makeLevel('Kelas 11', 11);
        $class = $this->makeClass($levelA, $year);
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $structure = $this->makeStructure($curriculum, $levelB, [$mtk], 1, $year);

        $this->postJson("/api/v1/classes/{$class->id}/apply-curriculum", [
            'curriculum_structure_id' => $structure->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('curriculum_structure_id');

        $this->assertDatabaseCount('class_subjects', 0);
    }

    public function test_structure_in_use_cannot_be_deleted(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $year = $this->makeYear();
        $level = $this->makeLevel();
        $class = $this->makeClass($level, $year);
        $mtk = $this->makeSubject('MTK', 'Matematika');

        $structure = $this->makeStructure($curriculum, $level, [$mtk], 1, $year);

        $this->postJson("/api/v1/classes/{$class->id}/apply-curriculum", [
            'curriculum_structure_id' => $structure->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/curriculum-structures/{$structure->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('curriculum_structures', ['id' => $structure->id, 'deleted_at' => null]);
    }

    public function test_class_curriculum_returns_structure_and_expected_subjects(): void
    {
        $this->actingAsRole('admin-sekolah');
        $curriculum = $this->makeCurriculum();
        $year = $this->makeYear();
        $level = $this->makeLevel();
        $class = $this->makeClass($level, $year);
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $bind = $this->makeSubject('BIND', 'Bahasa Indonesia');

        $structure = $this->makeStructure($curriculum, $level, [$mtk, $bind], 1, $year);

        $this->getJson("/api/v1/classes/{$class->id}/curriculum")
            ->assertOk()
            ->assertJsonPath('data.structure.id', $structure->id)
            ->assertJsonCount(2, 'data.subjects')
            ->assertJsonPath('data.missing_subject_ids', [$mtk->id, $bind->id]);
    }

    // =================================================================
    // RBAC
    // =================================================================

    public function test_guru_can_view_but_cannot_create(): void
    {
        $this->actingAsRole('guru');
        $curriculum = $this->makeCurriculum();
        $level = $this->makeLevel();
        $mtk = $this->makeSubject('MTK', 'Matematika');
        $this->makeStructure($curriculum, $level, [$mtk]);

        $this->getJson('/api/v1/curriculum-structures')->assertOk();
        $this->getJson('/api/v1/subject-groups')->assertOk();

        $this->postJson('/api/v1/subject-groups', ['code' => 'X', 'name' => 'Tidak Boleh'])
            ->assertForbidden();

        $this->postJson('/api/v1/programs', ['code' => 'Y', 'name' => 'Tidak Boleh'])
            ->assertForbidden();
    }

    public function test_guest_cannot_access_curriculum(): void
    {
        $this->getJson('/api/v1/curriculum-structures')->assertUnauthorized();
        $this->getJson('/api/v1/subject-groups')->assertUnauthorized();
        $this->getJson('/api/v1/programs')->assertUnauthorized();
        $this->getJson('/api/v1/subject-prerequisites')->assertUnauthorized();
    }
}
