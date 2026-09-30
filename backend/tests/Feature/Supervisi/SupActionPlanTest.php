<?php

namespace Tests\Feature\Supervisi;

use App\Models\User;
use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupActionPlan;

/**
 * RTL: penyusunan otomatis dari temuan, pelaporan kemajuan, verifikasi atasan,
 * papan pemantauan, dan pembatasan akses.
 */
class SupActionPlanTest extends SupTestCase
{
    /**
     * Siapkan kegiatan yang sudah difinalkan beserta temuan berrekomendasi.
     *
     * @return array{0: Employee, 1: User, 2: Employee, 3: User, 4: int}
     */
    protected function finalizedVisitWithFindings(int $findingCount = 2): array
    {
        $supervisor = $this->employee(['full_name' => 'Pak Supervisor', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $supervisor, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $supervisorUser = $this->employeeWithAccount($supervisor, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Bu Guru']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        $instrument = $this->publishedInstrument();
        $version = $instrument['version']->load('sections.indicators.options');

        $visitId = (int) $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $version->id,
        ])->assertCreated()->json('data.visit.id');

        $answers = [];
        foreach ($version->indicators as $indicator) {
            $answers[] = match ($indicator->input_type->value) {
                'SCALE' => ['indicator_id' => $indicator->id, 'option_id' => $indicator->options->sortByDesc('score')->first()->id],
                'NUMBER' => ['indicator_id' => $indicator->id, 'value_number' => 8],
                'BOOLEAN' => ['indicator_id' => $indicator->id, 'value_text' => 'YES'],
                default => ['indicator_id' => $indicator->id, 'value_long_text' => 'Catatan observasi.'],
            };
        }

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $answers])
            ->assertOk();

        $findings = [];
        for ($index = 1; $index <= $findingCount; $index++) {
            $findings[] = [
                'component' => 'PELAKSANAAN',
                'priority' => $index === 1 ? 'URGENT' : 'LOW',
                'weakness' => "Kelemahan {$index}",
                'cause' => "Penyebab {$index}",
                'recommendation' => "Rekomendasi tindak lanjut {$index}",
            ];
        }

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/findings", ['findings' => $findings])
            ->assertOk();

        // Wawancara pasca-observasi wajib sebelum finalisasi.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/conferences/POST", [
                'held_at' => now()->toIso8601String(),
                'agreements' => ['Menindaklanjuti temuan observasi'],
                'reflection' => 'Guru menyepakati rencana perbaikan.',
            ])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertOk();

        return [$supervisor, $supervisorUser, $teacher, $teacherUser, $visitId];
    }

    public function test_rtl_disusun_otomatis_dari_temuan(): void
    {
        [, $supervisorUser, , , $visitId] = $this->finalizedVisitWithFindings(2);

        $response = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/action-plan")
            ->assertCreated();

        $plan = $response->json('data.plan');
        $this->assertCount(2, $plan['items']);
        $this->assertSame('DRAFT', $plan['status']);

        $statements = array_column($plan['items'], 'statement');
        $this->assertContains('Rekomendasi tindak lanjut 1', $statements);
        $this->assertContains('Rekomendasi tindak lanjut 2', $statements);

        $pertama = collect($plan['items'])->firstWhere('statement', 'Rekomendasi tindak lanjut 1');
        $this->assertSame('Penyebab 1', $pertama['support_needed']);

        // Prioritas mendesak mendapat tenggat lebih pendek daripada prioritas rendah.
        $tenggat = collect($plan['items'])->pluck('due_date')->sort()->values();
        $this->assertLessThan($tenggat->last(), $tenggat->first());

        // Satu kegiatan hanya boleh memiliki satu RTL.
        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/action-plan")
            ->assertStatus(422);
    }

    public function test_rtl_tidak_dapat_dibuat_sebelum_hasil_difinalkan(): void
    {
        $supervisor = $this->employee(['full_name' => 'Pak Supervisor']);
        $this->registerSupervisor(['employee' => $supervisor, 'can_finalize' => true]);
        $supervisorUser = $this->employeeWithAccount($supervisor, 'kepala-sekolah');
        $teacher = $this->employee(['full_name' => 'Bu Guru']);
        $instrument = $this->publishedInstrument();

        $visitId = (int) $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
        ])->assertCreated()->json('data.visit.id');

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/action-plan")
            ->assertStatus(422);
    }

    public function test_kemajuan_butir_dan_verifikasi_rtl(): void
    {
        [, $supervisorUser, $teacher, $teacherUser, $visitId] = $this->finalizedVisitWithFindings(2);

        $planId = (int) $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/action-plan")
            ->assertCreated()
            ->json('data.plan.id');

        $items = SupActionPlan::query()->findOrFail($planId)->items()->orderBy('id')->get();
        $first = $items[0];
        $second = $items[1];

        // Laporan kemajuan oleh guru: 50% → butir berjalan, RTL berstatus berjalan.
        $progress = $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/action-plans/items/{$first->id}/progress", [
                'progress_percent' => 50,
                'note' => 'Sudah menyusun draf pertanyaan HOTS.',
            ])
            ->assertOk();

        $this->assertSame('IN_PROGRESS', $progress->json('data.plan.items.0.status'));
        $this->assertSame('ONGOING', $progress->json('data.plan.status'));

        // Verifikasi belum boleh: masih ada butir lain yang tertunda.
        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/action-plans/{$planId}/verify")
            ->assertStatus(422);

        // Selesaikan seluruh butir.
        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/action-plans/items/{$first->id}/progress", ['progress_percent' => 100])
            ->assertOk();

        $done = $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/action-plans/items/{$second->id}/progress", [
                'progress_percent' => 100,
                'attachment_path' => 'evidence/rtl-2.pdf',
            ])
            ->assertOk();

        $this->assertSame('DONE', $done->json('data.plan.status'));

        $verified = $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/action-plans/{$planId}/verify", ['note' => 'Bukti lengkap.'])
            ->assertOk();

        $this->assertSame('VERIFIED', $verified->json('data.plan.status'));
        $this->assertNotNull($verified->json('data.plan.verified_at'));

        // Setelah diverifikasi, butir terkunci.
        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/action-plans/items/{$first->id}/progress", ['progress_percent' => 40])
            ->assertStatus(422);
    }

    public function test_papan_rtl_menonjolkan_keterlambatan(): void
    {
        [, $supervisorUser, , , $visitId] = $this->finalizedVisitWithFindings(2);

        $planId = (int) $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/action-plan")
            ->assertCreated()
            ->json('data.plan.id');

        $item = SupActionItem::query()->where('action_plan_id', $planId)->orderBy('id')->firstOrFail();

        // Tenggat dimundurkan ke masa lalu → butir dianggap terlambat.
        $this->actingAsUser($supervisorUser)
            ->patchJson("/api/v1/supervisi/action-plans/items/{$item->id}", [
                'due_date' => now()->subDays(3)->toDateString(),
            ])
            ->assertOk();

        $board = $this->actingAsUser($supervisorUser)
            ->getJson('/api/v1/supervisi/action-plans')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $board->json('data.buckets.overdue'));
        $this->assertTrue(
            collect($board->json('data.rows'))->flatMap(fn ($row) => $row['items'])->contains('is_overdue', true)
        );

        $overdueOnly = $this->actingAsUser($supervisorUser)
            ->getJson('/api/v1/supervisi/action-plans?overdue_only=1')
            ->assertOk();

        $this->assertCount(1, $overdueOnly->json('data.rows'));
    }

    public function test_rtl_hanya_terlihat_oleh_pihak_terkait(): void
    {
        [, $supervisorUser, , $teacherUser, $visitId] = $this->finalizedVisitWithFindings(1);

        $planId = (int) $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/action-plan")
            ->assertCreated()
            ->json('data.plan.id');

        $lain = $this->employee(['full_name' => 'Pak Guru Lain']);
        $lainUser = $this->employeeWithAccount($lain, 'guru');

        $board = $this->actingAsUser($lainUser)->getJson('/api/v1/supervisi/action-plans')->assertOk();
        $this->assertSame([], $board->json('data.rows'));

        $this->actingAsUser($lainUser)
            ->getJson("/api/v1/supervisi/action-plans/{$planId}")
            ->assertStatus(403);

        // Guru yang bersangkutan tetap dapat membuka RTL-nya.
        $this->actingAsUser($teacherUser)
            ->getJson("/api/v1/supervisi/action-plans/{$planId}")
            ->assertOk();
    }
}
