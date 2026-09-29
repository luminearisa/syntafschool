<?php

namespace Tests\Feature\Supervisi;

use App\Modules\Supervisi\Models\SupAssignment;
use App\Modules\Supervisi\Models\SupSupervisor;

/**
 * Pengaturan supervisor: memilih siapa saja yang boleh menilai, beserta
 * batas kewenangannya.
 */
class SupSupervisorDirectoryTest extends SupTestCase
{
    public function test_kepala_sekolah_dapat_mendaftarkan_supervisor(): void
    {
        $this->actingAsRole('kepala-sekolah');
        $employee = $this->employee(['full_name' => 'Bu Rina', 'position' => 'Wakil Kepala Sekolah']);

        $created = $this->postJson('/api/v1/supervisi/supervisors', [
            'employee_id' => $employee->id,
            'kind' => 'WAKASEK',
            'can_finalize' => true,
            'max_active_cases' => 12,
        ])->assertCreated();

        $this->assertSame('WAKASEK', $created->json('data.kind'));
        $this->assertTrue($created->json('data.can_finalize'));

        // Pegawai yang sama tidak dapat didaftarkan dua kali.
        $this->postJson('/api/v1/supervisi/supervisors', [
            'employee_id' => $employee->id,
            'kind' => 'MENTOR',
        ])->assertStatus(422);
    }

    public function test_rekan_sejawat_tidak_pernah_boleh_memfinalkan_nilai(): void
    {
        $this->actingAsRole('kepala-sekolah');
        $employee = $this->employee();

        $created = $this->postJson('/api/v1/supervisi/supervisors', [
            'employee_id' => $employee->id,
            'kind' => 'PEER',
            'can_finalize' => true, // diminta, tetapi ditolak oleh aturan server
            'can_appraise' => true,
        ])->assertCreated();

        $this->assertFalse($created->json('data.can_finalize'));
        $this->assertFalse($created->json('data.can_appraise'));

        $registry = SupSupervisor::query()->findOrFail($created->json('data.id'));
        $this->assertFalse($registry->mayFinalize());
        $this->assertFalse($registry->mayAppraise());
    }

    public function test_hanya_pemegang_izin_yang_dapat_mengubah_registry(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/supervisi/supervisors')->assertStatus(403);

        $this->actingAsRole('kepala-sekolah');
        $this->getJson('/api/v1/supervisi/supervisors')->assertOk();
    }

    public function test_calon_supervisor_menyarankan_jenis_dari_jabatan(): void
    {
        $kepala = $this->employee(['full_name' => 'Pak Kepala', 'position' => 'Kepala Sekolah']);
        $this->employeeWithAccount($kepala, 'kepala-sekolah');
        $this->employee(['full_name' => 'Bu Koordinator', 'position' => 'Koordinator Mapel IPA']);

        $this->actingAsRole('kepala-sekolah');
        $response = $this->getJson('/api/v1/supervisi/supervisors/candidates')->assertOk();

        $rows = collect($response->json('data'))->keyBy('full_name');

        $this->assertSame('KEPALA_SEKOLAH', $rows['Pak Kepala']['suggested_kind']);
        $this->assertSame('KOORDINATOR', $rows['Bu Koordinator']['suggested_kind']);
    }

    public function test_supervisor_tidak_boleh_menilai_dirinya_sendiri(): void
    {
        $kepala = $this->employee(['full_name' => 'Pak Kepala']);
        $this->registerSupervisor(['employee' => $kepala, 'kind' => 'KEPALA_SEKOLAH']);

        $this->actingAsRole('kepala-sekolah');

        $eligible = $this->getJson('/api/v1/supervisi/supervisors/eligible?supervisee_employee_id='.$kepala->id)
            ->assertOk();

        $row = collect($eligible->json('data'))->firstWhere('employee_id', $kepala->id);
        $this->assertFalse($row['eligible']);
        $this->assertStringContainsString('dirinya sendiri', $row['reason']);
    }

    public function test_penugasan_wajib_memakai_supervisor_terdaftar(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $programId = $this->postJson('/api/v1/supervisi/programs', [
            'title' => 'Program Supervisi Semester Ganjil',
            'scope' => 'GABUNGAN',
        ])->assertCreated()->json('data.id');

        $supervisee = $this->employee();
        $belumTerdaftar = $this->employee();

        $this->postJson("/api/v1/supervisi/programs/{$programId}/assignments", [
            'supervisor_employee_id' => $belumTerdaftar->id,
            'supervisee_employee_id' => $supervisee->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.supervisor_employee_id.0', fn (string $message) => str_contains($message, 'belum terdaftar'));

        $supervisor = $this->registerSupervisor(['kind' => 'KEPALA_SEKOLAH']);

        $this->postJson("/api/v1/supervisi/programs/{$programId}/assignments", [
            'supervisor_employee_id' => $supervisor->employee_id,
            'supervisee_employee_id' => $supervisee->id,
            'approach' => 'COLLABORATIVE',
            'min_visits' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.approach', 'COLLABORATIVE')
            ->assertJsonPath('data.min_visits', 2);
    }

    public function test_kapasitas_supervisor_dibatasi(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $programId = $this->postJson('/api/v1/supervisi/programs', [
            'title' => 'Program Terbatas',
        ])->json('data.id');

        $supervisor = $this->registerSupervisor([
            'kind' => 'KEPALA_SEKOLAH',
            'max_active_cases' => 1,
        ]);

        $this->postJson("/api/v1/supervisi/programs/{$programId}/assignments", [
            'supervisor_employee_id' => $supervisor->employee_id,
            'supervisee_employee_id' => $this->employee()->id,
        ])->assertCreated();

        $this->postJson("/api/v1/supervisi/programs/{$programId}/assignments", [
            'supervisor_employee_id' => $supervisor->employee_id,
            'supervisee_employee_id' => $this->employee()->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.supervisor_employee_id.0', fn (string $message) => str_contains($message, 'Kapasitas'));
    }

    public function test_peta_penugasan_menandai_supervisor_belum_terdaftar(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $supervisor = $this->registerSupervisor(['kind' => 'KEPALA_SEKOLAH']);
        $program = \App\Modules\Supervisi\Models\SupProgram::query()->create([
            'title' => 'Program Peta',
            'status' => 'PUBLISHED',
        ]);

        SupAssignment::query()->create([
            'program_id' => $program->id,
            'supervisor_employee_id' => $supervisor->employee_id,
            'supervisee_employee_id' => $this->employee()->id,
            'role' => 'UTAMA',
            'approach' => 'COLLABORATIVE',
            'is_active' => true,
        ]);

        // Hapus registry: penugasan tetap ada, peta harus memberi peringatan.
        $supervisor->forceDelete();

        $coverage = $this->getJson('/api/v1/supervisi/supervisors/coverage')->assertOk();

        $this->assertNotEmpty($coverage->json('data.warnings'));
        $this->assertStringContainsString('belum terdaftar', implode(' ', $coverage->json('data.warnings')));
    }

    public function test_pengaturan_modul_dapat_disimpan_dan_dibaca(): void
    {
        $this->actingAsRole('kepala-sekolah');

        $this->putJson('/api/v1/supervisi/settings', [
            'values' => [
                'min_visits_guru_full_observation' => 1,
                'min_visits_guru_walkthrough' => 3,
                'supervision_weight_percent' => 40,
                'publish_result_to_supervisee' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.values.supervision_weight_percent', 40);

        $this->getJson('/api/v1/supervisi/settings')
            ->assertOk()
            ->assertJsonPath('data.values.min_visits_guru_walkthrough', 3);

        $this->putJson('/api/v1/supervisi/settings', [
            'values' => ['supervision_weight_percent' => 140],
        ])->assertStatus(422);

        $this->putJson('/api/v1/supervisi/settings', [
            'values' => ['tidak_dikenal' => 1],
        ])->assertStatus(422);
    }
}
