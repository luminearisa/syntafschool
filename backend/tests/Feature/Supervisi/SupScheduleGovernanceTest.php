<?php

namespace Tests\Feature\Supervisi;

use App\Modules\Supervisi\Models\SupProgram;
use Laravel\Sanctum\Sanctum;

/** Tata kelola jadwal supervisi: hanya supervisor terdaftar yang boleh dijadwalkan. */
class SupScheduleGovernanceTest extends SupTestCase
{
    protected function program(): SupProgram
    {
        return SupProgram::query()->create([
            'title' => 'Program Jadwal',
            'status' => 'PUBLISHED',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(4)->toDateString(),
        ]);
    }

    public function test_jadwal_menolak_supervisor_yang_belum_terdaftar(): void
    {
        $this->actingAsRole('kepala-sekolah');
        $program = $this->program();

        $this->postJson('/api/v1/supervisi/schedules', [
            'program_id' => $program->id,
            'supervisor_employee_id' => $this->employee()->id,
            'supervisee_employee_id' => $this->employee()->id,
            'activity_kind' => 'CLASS_OBSERVATION',
            'planned_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonPath('errors.supervisor_employee_id.0', fn (string $message) => str_contains($message, 'belum terdaftar'));
    }

    public function test_jadwal_menolak_penilai_dan_yang_dinilai_orang_yang_sama(): void
    {
        $this->actingAsRole('kepala-sekolah');
        $program = $this->program();
        $pegawai = $this->employee();
        $this->registerSupervisor(['employee' => $pegawai, 'kind' => 'KEPALA_SEKOLAH']);

        $this->postJson('/api/v1/supervisi/schedules', [
            'program_id' => $program->id,
            'supervisor_employee_id' => $pegawai->id,
            'supervisee_employee_id' => $pegawai->id,
            'planned_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_jadwal_lengkap_dapat_dibuat_diundang_diubah_dan_dibatalkan(): void
    {
        $this->actingAsRole('kepala-sekolah');
        $program = $this->program();
        $supervisor = $this->registerSupervisor(['kind' => 'KEPALA_SEKOLAH']);
        $supervisee = $this->employee();

        $schedule = $this->postJson('/api/v1/supervisi/schedules', [
            'program_id' => $program->id,
            'supervisor_employee_id' => $supervisor->employee_id,
            'supervisee_employee_id' => $supervisee->id,
            'activity_kind' => 'CLASS_OBSERVATION',
            'planned_at' => now()->addDay()->toIso8601String(),
            'duration_minutes' => 40,
            'focus' => 'Keterlibatan peserta didik pada kegiatan inti',
        ])->assertCreated();

        $scheduleId = $schedule->json('data.id');
        $this->assertSame('PLANNED', $schedule->json('data.status'));

        $this->postJson("/api/v1/supervisi/schedules/{$scheduleId}/invite")
            ->assertOk()
            ->assertJsonPath('data.status', 'INVITED');

        // Ubah jadwal wajib disertai alasan.
        $this->postJson("/api/v1/supervisi/schedules/{$scheduleId}/reschedule", [
            'planned_at' => now()->addDays(3)->toIso8601String(),
        ])->assertStatus(422);

        $this->postJson("/api/v1/supervisi/schedules/{$scheduleId}/reschedule", [
            'planned_at' => now()->addDays(3)->toIso8601String(),
            'reason' => 'Guru sedang mengikuti diklat',
        ])->assertOk()
            ->assertJsonPath('data.status', 'RESCHEDULED');

        $this->postJson("/api/v1/supervisi/schedules/{$scheduleId}/cancel", [
            'reason' => 'Kegiatan sekolah',
        ])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_guru_hanya_melihat_jadwal_miliknya(): void
    {
        $program = $this->program();
        $supervisor = $this->registerSupervisor(['kind' => 'KEPALA_SEKOLAH']);

        $mine = $this->employee(['full_name' => 'Guru Saya']);
        $other = $this->employee(['full_name' => 'Guru Lain']);

        foreach ([$mine, $other] as $supervisee) {
            \App\Modules\Supervisi\Models\SupSchedule::query()->create([
                'program_id' => $program->id,
                'supervisor_employee_id' => $supervisor->employee_id,
                'supervisee_employee_id' => $supervisee->id,
                'activity_kind' => 'CLASS_OBSERVATION',
                'planned_at' => now()->addDay(),
                'status' => 'PLANNED',
            ]);
        }

        $user = $this->employeeWithAccount($mine, 'guru');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/supervisi/schedules')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Guru Saya', $response->json('data.0.supervisee'));

        // Tanpa kaitan data pegawai, jadwal tidak boleh terlihat sama sekali.
        $lonely = \App\Models\User::factory()->create(['is_active' => true]);
        $lonely->syncRoles(['guru']);
        Sanctum::actingAs($lonely);

        $this->getJson('/api/v1/supervisi/schedules')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guru_hanya_dapat_melihat_jadwal(): void
    {
        $this->actingAsRole('guru');

        $this->getJson('/api/v1/supervisi/schedules')->assertOk();

        $this->postJson('/api/v1/supervisi/schedules', [
            'program_id' => 1,
            'supervisor_employee_id' => 1,
            'supervisee_employee_id' => 2,
            'planned_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(403);
    }
}
