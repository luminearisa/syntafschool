<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkFollowUpService;
use App\Modules\Bk\Application\Services\BkInterventionService;
use App\Modules\Bk\Application\Services\BkPlanService;
use App\Modules\Bk\Domain\Enums\CaseStatus;
use App\Modules\Bk\Domain\Enums\ClosureReason;
use App\Modules\Bk\Domain\Enums\EscalationLevel;
use App\Modules\Bk\Domain\Enums\FollowUpType;
use App\Modules\Bk\Domain\Enums\InterventionType;
use App\Modules\Bk\Exceptions\BkValidationException;

/**
 * Penutupan kasus: tidak boleh ditutup selama masih ada pekerjaan menggantung,
 * dan pembukaan kembali harus meninggalkan jejak.
 */
class BkCaseClosureTest extends BkTestCase
{
    public function test_kasus_tidak_dapat_ditutup_bila_tindak_lanjut_masih_menggantung(): void
    {
        $family = $this->makeStudentWithFamily('Fauzan');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);
        $this->assignCase($counselor, $case, $counselor);

        app(BkFollowUpService::class)->create($counselor, $case, [
            'title' => 'Cek perkembangan nilai',
            'type' => FollowUpType::PROGRESS_REVIEW->value,
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $this->expectException(BkValidationException::class);

        $this->caseService()->close($counselor, $case, ClosureReason::GOAL_ACHIEVED);
    }

    public function test_penutupan_berhasil_setelah_semua_tuntas_dan_dapat_dibuka_kembali(): void
    {
        $family = $this->makeStudentWithFamily('Hanif');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $this->actAs($coordinator);
        $case = $this->makeCase($coordinator, $family['student']);
        $this->assignCase($coordinator, $case, $counselor);

        // Intervensi selesai tanpa tindak lanjut.
        $intervention = app(BkInterventionService::class)->create($counselor, $case, [
            'type' => InterventionType::ACADEMIC_SUPPORT->value,
            'title' => 'Pendampingan belajar dua pekan',
        ]);

        app(BkInterventionService::class)->update($counselor, $intervention, [
            'status' => 'COMPLETED',
            'result' => 'Nilai naik pada penilaian harian.',
            'follow_up_required' => false,
        ]);

        $closed = $this->caseService()->close($counselor, $case, ClosureReason::GOAL_ACHIEVED, 'Sasaran tercapai.');
        $this->assertSame(CaseStatus::CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);

        $reopened = $this->caseService()->reopen($coordinator, $closed, 'Keluhan baru muncul kembali.');
        $this->assertSame(CaseStatus::REOPENED, $reopened->status);
        $this->assertSame(1, $reopened->reopen_count);

        $this->assertDatabaseHas('bk_case_events', ['case_id' => $case->id, 'event_type' => 'CASE_CLOSED']);
        $this->assertDatabaseHas('bk_case_events', ['case_id' => $case->id, 'event_type' => 'CASE_REOPENED']);
    }

    public function test_hanya_koordinator_yang_dapat_membuka_kembali_kasus(): void
    {
        $family = $this->makeStudentWithFamily('Ibrahim');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $this->actAs($coordinator);
        $case = $this->makeCase($coordinator, $family['student']);
        $this->assignCase($coordinator, $case, $counselor);

        $closed = $this->caseService()->close($coordinator, $case, ClosureReason::STABLE);

        $this->expectException(BkValidationException::class);

        $this->caseService()->reopen($counselor, $closed, 'Ingin membuka kembali.');
    }

    public function test_kasus_yang_sudah_ditutup_tidak_dapat_melompat_kembali(): void
    {
        $family = $this->makeStudentWithFamily('Jamilah');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $this->actAs($coordinator);
        $case = $this->makeCase($coordinator, $family['student']);
        $this->assignCase($coordinator, $case, $counselor);

        $closed = $this->caseService()->close($coordinator, $case, ClosureReason::NO_FURTHER_ACTION);

        // CLOSED hanya boleh menuju REOPENED; melompat ke ASSESSMENT ditolak.
        $this->expectException(BkValidationException::class);

        $this->caseService()->transition($coordinator, $closed, CaseStatus::ASSESSMENT);
    }

    public function test_eskalasi_meninggalkan_jejak_dan_menaikkan_tingkat(): void
    {
        $family = $this->makeStudentWithFamily('Kamil');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $this->actAs($coordinator);
        $case = $this->makeCase($coordinator, $family['student'], ['case_type' => 'SAFEGUARDING_CONCERN']);
        $this->assignCase($coordinator, $case, $counselor);

        $escalated = $this->caseService()->escalate($counselor, $case, EscalationLevel::MANAGEMENT, 'Perlu keputusan kepala sekolah.');

        $this->assertSame(EscalationLevel::MANAGEMENT, $escalated->escalation_level);
        $this->assertTrue($escalated->risk_flag);
        $this->assertDatabaseHas('bk_case_events', ['case_id' => $case->id, 'event_type' => 'ESCALATED']);
        $this->assertDatabaseHas('bk_notification_logs', ['event' => 'ESCALATION']);
    }

    public function test_rencana_konseling_mewajibkan_indikator_keberhasilan(): void
    {
        $family = $this->makeStudentWithFamily('Latifah');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);

        $this->expectException(BkValidationException::class);

        app(BkPlanService::class)->create($counselor, $case, [
            'title' => 'Rencana dukungan belajar',
            'problem_statement' => 'Nilai menurun pada tiga mata pelajaran.',
            'goals' => [
                ['goal' => 'Meningkatkan nilai matematika', 'success_indicator' => ''],
            ],
        ]);
    }

    public function test_intervensi_selesai_dengan_tindak_lanjut_melahirkan_tindak_lanjut_baru(): void
    {
        $family = $this->makeStudentWithFamily('Muhaimin');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);

        $intervention = app(BkInterventionService::class)->create($counselor, $case, [
            'type' => InterventionType::PARENT_MEETING->value,
            'title' => 'Pertemuan dengan orang tua',
            'follow_up_required' => true,
        ]);

        app(BkInterventionService::class)->update($counselor, $intervention, [
            'status' => 'COMPLETED',
            'result' => 'Orang tua bersedia mendampingi belajar malam.',
            'follow_up_required' => true,
            'follow_up_due' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertDatabaseHas('bk_follow_ups', [
            'case_id' => $case->id,
            'intervention_id' => $intervention->id,
            'status' => 'PENDING',
        ]);
    }
}
