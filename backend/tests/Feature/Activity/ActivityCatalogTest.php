<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityCategory;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivitySkill;

/**
 * Katalog: kategori berjenjang, keterampilan, penerbitan program, dan kapasitas.
 */
class ActivityCatalogTest extends ActivityTestCase
{
    public function test_kategori_bawaan_tersedia_berjenjang(): void
    {
        $tree = app(\App\Modules\Activity\Application\Services\ActivityCategoryService::class)->tree();

        $codes = array_column($tree, 'code');

        $this->assertContains('SPORT', $codes);
        $this->assertContains('ART', $codes);

        $sport = collect($tree)->firstWhere('code', 'SPORT');

        $this->assertNotEmpty($sport['children'], 'Kategori olahraga seharusnya memiliki sub-kategori.');
        $this->assertContains('Indoor', array_column($sport['children'], 'name'));
    }

    public function test_kategori_tidak_dapat_dihapus_bila_masih_dipakai_program(): void
    {
        $this->makeProgram();

        $category = ActivityCategory::query()->where('code', 'SPORT')->firstOrFail();

        // Menghapus kategori yang masih dipakai adalah *konflik keadaan*, bukan galat validasi.
        $this->expectException(\App\Modules\Activity\Domain\Exceptions\ActivityConflictException::class);

        app(\App\Modules\Activity\Application\Services\ActivityCategoryService::class)->deleteCategory($category);
    }

    public function test_program_dapat_disimpan_bersama_keterampilan_dan_tujuannya(): void
    {
        $admin = $this->actingAsRole('ACTIVITY_ADMIN');
        $skill = ActivitySkill::query()->where('code', 'TEAMWORK')->firstOrFail();
        $objective = \App\Modules\Activity\Models\ActivityObjective::query()->where('code', 'TEAMWORK_DEV')->firstOrFail();

        $program = app(\App\Modules\Activity\Application\Services\ActivityProgramService::class)->create([
            'code' => 'FUTSAL-7',
            'name' => 'Futsal Kelas VII',
            'category_id' => ActivityCategory::query()->where('code', 'SPORT')->firstOrFail()->id,
            'skills' => [$skill->id],
            'objectives' => [['id' => $objective->id, 'target_level' => 'COMPETENT']],
        ], $admin);

        $this->assertDatabaseHas('activity_program_skills', ['program_id' => $program->id, 'skill_id' => $skill->id]);
        $this->assertDatabaseHas('activity_program_objectives', ['program_id' => $program->id, 'objective_id' => $objective->id]);
        $this->assertSame('DRAFT', $program->status->value, 'Program baru harus berupa draf.');
    }

    public function test_penerbitan_program_menolak_konfigurasi_yang_belum_lengkap(): void
    {
        $admin = $this->actingAsRole('ACTIVITY_ADMIN');

        $program = $this->makeProgram([
            'status' => 'DRAFT',
            'maximum_capacity' => null,
            'coach_employee_id' => null,
        ]);

        $this->expectException(ActivityValidationException::class);

        app(\App\Modules\Activity\Application\Services\ActivityProgramService::class)->publish($program, $admin);
    }

    public function test_program_terbit_setelah_kapasitas_jadwal_dan_pembina_lengkap(): void
    {
        $admin = $this->actingAsRole('ACTIVITY_ADMIN');
        $coach = $this->makeCoach('Pembina Futsal');

        $program = $this->makeProgram([
            'status' => 'DRAFT',
            'coach_employee_id' => $coach['employee']->id,
        ]);

        $this->makeSchedule($program);

        $published = app(\App\Modules\Activity\Application\Services\ActivityProgramService::class)->publish($program, $admin);

        $this->assertSame('PUBLISHED', $published->status->value);
        $this->assertNotNull($published->published_at);
    }

    public function test_kapasitas_menghitung_anggota_aktif_dan_sisa_kursi(): void
    {
        $program = $this->makeProgram(['maximum_capacity' => 2]);
        $service = app(\App\Modules\Activity\Application\Services\ActivityProgramService::class);

        foreach (['Ani', 'Budi'] as $name) {
            $student = $this->makeStudent($name);

            ActivityMembership::query()->create([
                'program_id' => $program->id,
                'period_id' => $this->period->id,
                'student_id' => $student->id,
                'status' => 'ACTIVE',
                'role' => 'MEMBER',
                'joined_at' => now()->toDateString(),
            ]);
        }

        $capacity = $service->capacity($program);

        $this->assertSame(2, $capacity['active']);
        $this->assertTrue($capacity['is_full']);
        $this->assertSame(0, $capacity['seats_left']);
    }

    public function test_katalog_siswa_hanya_menampilkan_program_yang_dibuka(): void
    {
        $this->makeProgram(['code' => 'OPEN-1', 'name' => 'Basket', 'status' => 'PUBLISHED']);
        $this->makeProgram(['code' => 'DRAFT-1', 'name' => 'Robotics Draft', 'status' => 'DRAFT']);

        $this->actingAsRole('ACTIVITY_MANAGER');

        $response = $this->getJson('/api/v1/activities/discover');

        $response->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Basket', $names);
        $this->assertNotContains('Robotics Draft', $names);
    }

    public function test_uji_kelayakan_mengembalikan_alasan_yang_dapat_dibaca_siswa(): void
    {
        $class = $this->makeClass('VII A');
        $student = $this->makeStudent('Cinta', $class);
        $this->actingAsRole('ACTIVITY_ADMIN');

        $program = $this->makeProgram([
            'code' => 'SENIOR',
            'name' => 'Basket Senior',
            'minimum_grade_level' => 10,
            'gender' => 'ANY',
        ]);

        $response = $this->postJson("/api/v1/activities/programs/{$program->id}/eligibility", [
            'student_id' => $student->id,
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('data.passed'));
        $this->assertSame('GRADE', $response->json('data.failures.0.code'));
    }
}
