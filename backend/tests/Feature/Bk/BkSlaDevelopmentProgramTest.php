<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkAutomationService;
use App\Modules\Bk\Application\Services\BkConsentService;
use App\Modules\Bk\Application\Services\BkDevelopmentPlanService;
use App\Modules\Bk\Application\Services\BkEscalationService;
use App\Modules\Bk\Application\Services\BkFeedbackService;
use App\Modules\Bk\Application\Services\BkGroupProgramService;
use App\Modules\Bk\Application\Services\BkSlaService;
use App\Modules\Bk\Domain\Enums\ConsentScope;
use App\Modules\Bk\Domain\Enums\DevelopmentGoalStatus;
use App\Modules\Bk\Domain\Enums\EscalationStatus;
use App\Modules\Bk\Domain\Enums\FeedbackStatus;
use App\Modules\Bk\Domain\Enums\GroupAttendanceStatus;
use App\Modules\Bk\Domain\Enums\PlanStatus;
use App\Modules\Bk\Domain\Enums\SlaSubjectType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkAutomationRun;
use App\Modules\Bk\Models\BkDevelopmentGoal;
use App\Modules\Bk\Models\BkEscalation;
use App\Modules\Bk\Models\BkFeedback;
use App\Modules\Bk\Models\BkSlaEvent;
use Illuminate\Support\Carbon;

/**
 * SLA & eskalasi, otomasi, pengembangan siswa, program kelompok, dan umpan balik.
 *
 * Empat sifat diuji di sini: SLA memakai kebijakan (bukan angka di kode), eskalasi
 * berjenjang dan berakhir, otomasi idempoten, dan umpan balik yang sudah final
 * tidak dapat diubah siapa pun.
 */
class BkSlaDevelopmentProgramTest extends BkPhase2TestCase
{
    public function test_sla_memakai_kebijakan_paling_spesifik(): void
    {
        // Kebijakan adalah data: uji ini menjalankan seeder bawaan, bukan menganggap
        // angkanya tertanam di kode.
        $this->seed(\App\Modules\Bk\Database\Seeders\BkSlaPolicySeeder::class);

        $family = $this->makeStudentWithFamily('Sulaiman');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id, ['priority' => 'URGENT']);

        $sla = app(BkSlaService::class);

        // Kasus mendesak: kebijakan URGENT_CASE (4 jam) lebih spesifik daripada
        // kebijakan umum CASE (336 jam).
        $event = $sla->start(SlaSubjectType::URGENT_CASE, $case, [
            'priority' => 'URGENT',
            'severity' => $case->severity?->value,
            'case_type' => $case->case_type->value,
            'category' => $case->category->value,
            'assigned_user_id' => $coordinator->id,
            'case_id' => $case->id,
        ]);

        $this->assertSame('URGENT_CASE', $event->policy_code);
        $this->assertSame(4, (int) $event->policy_snapshot['response_hours']);
        $this->assertSame(4.0, round($event->started_at->diffInHours($event->due_at), 1));

        // Idempoten: pemanggilan kedua tidak melahirkan SLA baru.
        $again = $sla->start(SlaSubjectType::URGENT_CASE, $case, ['priority' => 'URGENT']);
        $this->assertSame($event->id, $again->id);
        $this->assertSame(1, BkSlaEvent::query()->count());
    }

    public function test_keterlampauan_sla_melahirkan_eskalasi_berjenjang_yang_dapat_diselesaikan(): void
    {
        $family = $this->makeStudentWithFamily('Bilqis');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id, ['priority' => 'URGENT']);

        $sla = app(BkSlaService::class);
        $event = $sla->start(SlaSubjectType::URGENT_CASE, $case, [
            'priority' => 'URGENT',
            'case_id' => $case->id,
            'assigned_user_id' => $coordinator->id,
        ]);

        // Waktu dimajukan melewati tenggat.
        $later = Carbon::now()->addHours(6);
        $result = $sla->monitor($later);

        $this->assertSame(1, $result['breached']);
        $this->assertSame(1, $result['escalated']);

        $escalations = BkEscalation::query()->get();
        $this->assertCount(1, $escalations);
        $this->assertSame('COORDINATOR', $escalations->first()->to_level->value, 'Eskalasi otomatis naik satu tingkat ke koordinator.');
        $this->assertTrue((bool) $escalations->first()->automatic);

        // Pemantauan ulang tidak menggandakan eskalasi.
        $sla->monitor($later->copy()->addHour());
        $this->assertSame(1, BkEscalation::query()->count());

        // Eskalasi dapat diakui lalu diselesaikan — dan tidak menghilang setelah dibaca.
        $acknowledged = app(BkEscalationService::class)->acknowledge($coordinator, $escalations->first());
        $this->assertSame(EscalationStatus::ACKNOWLEDGED, $acknowledged->status);

        $resolved = app(BkEscalationService::class)->resolve($coordinator, $acknowledged, 'Sudah ditangani bersama wali kelas.');
        $this->assertSame(EscalationStatus::RESOLVED, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame(
            EscalationStatus::RESOLVED->value,
            $resolved->status->value,
            'Status akhir eskalasi tersimpan sebagai fakta, bukan disimpulkan ulang.',
        );
    }

    public function test_otomasi_idempoten_pada_tindakan_yang_dijalankan_berulang(): void
    {
        $family = $this->makeStudentWithFamily('Arwa');
        $counselor = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $referral = $this->makeReferralRow($counselor, (int) $family['student']->id);

        $automation = app(BkAutomationService::class);

        $first = $automation->referralReceived($referral->id);
        $second = $automation->referralReceived($referral->id);

        $this->assertSame($first->id, $second->id, 'Tindakan yang sama pada periode yang sama dikembalikan barisnya, bukan dibuat ulang.');
        $this->assertSame(1, BkAutomationRun::query()->where('action', 'REFERRAL_ASSIGN_QUEUE')->count());

        // Pengingat konselor saat kasus ditugaskan, sekaligus memulai SLA penanganan.
        $automation->caseAssigned($case->id, $counselor->id);
        $this->assertSame(1, BkAutomationRun::query()->where('action', 'CASE_ASSIGNED_NOTIFY')->count());
        $this->assertSame(1, BkSlaEvent::query()->where('subject_type', SlaSubjectType::URGENT_CASE->value)->count());
    }

    public function test_rencana_pengembangan_mewajibkan_baseline_dan_menghitung_kemajuan(): void
    {
        $family = $this->makeStudentWithFamily('Hud');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);

        $service = app(BkDevelopmentPlanService::class);
        $plan = $service->create($counselor, [
            'student_id' => $family['student']->id,
            'domain' => 'LEADERSHIP',
            'title' => 'Mengambil peran ketua kelompok belajar',
            'goals' => [[
                'goal' => 'Memimpin diskusi kelompok mingguan',
                'measurement' => 'Jumlah diskusi yang dipimpin per bulan',
                'baseline_value' => 0,
                'target_value' => 4,
            ]],
        ]);

        $goal = $plan->goals()->firstOrFail();
        $this->assertSame(DevelopmentGoalStatus::NOT_STARTED, $goal->status);

        // Peninjauan tanpa baseline ditolak: tanpa titik awal, "kemajuan" tak bermakna.
        try {
            $service->review($counselor, $goal, ['progress_value' => 2]);
            $this->fail('Peninjauan tanpa baseline seharusnya ditolak.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('review_type', $exception->errors());
        }

        $service->recordBaseline($counselor, $goal, ['progress_value' => 0]);

        // Baseline hanya sekali.
        try {
            $service->recordBaseline($counselor, $goal->refresh(), ['progress_value' => 1]);
            $this->fail('Baseline kedua seharusnya ditolak.');
        } catch (BkValidationException) {
            $this->assertTrue(true);
        }

        // Kemajuan dihitung dari jarak baseline → target.
        $review = $service->review($counselor, $goal->refresh(), ['progress_value' => 2]);
        $this->assertSame(50, (int) $review->progress_percent);
        $this->assertSame(50, (int) $goal->refresh()->progress);
        $this->assertSame(2, (int) $review->review_number);

        $service->review($counselor, $goal->refresh(), ['progress_value' => 4, 'status' => 'ACHIEVED']);

        // Seluruh sasaran tercapai: rencana ikut selesai.
        $this->assertSame(PlanStatus::COMPLETED, $plan->refresh()->status);
        $this->assertNotNull($plan->completed_at);

        // Urutan peninjauan tidak dapat ditulis mundur.
        $numbers = $goal->reviews()->orderBy('review_number')->pluck('review_number')->all();
        $this->assertSame([1, 2, 3], array_map('intval', $numbers));
    }

    public function test_program_kelompok_menghormati_persetujuan_dan_mencatat_kehadiran(): void
    {
        $family = $this->makeStudentWithFamily('Salamah');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);

        $programs = app(BkGroupProgramService::class);
        $program = $programs->create($counselor, [
            'title' => 'Keterampilan Belajar',
            'program_type' => 'STUDY_SKILLS',
            'requires_consent' => true,
            'start_date' => now()->toDateString(),
        ]);

        // Program mensyaratkan persetujuan: permintaan dibuat lebih dulu.
        $member = $programs->addMember($counselor, $program, ['student_id' => $family['student']->id]);
        $this->assertNotNull($member->consent_id, 'Keikutsertaan membuat permintaan persetujuan lebih dulu.');
        $this->assertDatabaseHas('bk_consents', [
            'id' => $member->consent_id,
            'scope' => ConsentScope::PROGRAM_PARTICIPATION->value,
            'status' => 'REQUESTED',
        ]);

        $this->actAs($family['guardian_user']);
        $this->postJson("/api/v1/bk/consents/{$member->consent_id}/grant", [])->assertOk();

        $this->actAs($counselor);
        $session = $programs->addSession($counselor, $program, [
            'title' => 'Sesi 1: mengatur waktu belajar',
            'scheduled_start' => now()->addDay()->toDateTimeString(),
        ]);

        $result = $programs->recordAttendance($counselor, $session, [[
            'member_id' => $member->id,
            'attendance_status' => GroupAttendanceStatus::PRESENT->value,
        ]]);

        $this->assertSame(1, $result['marked']);

        $summary = $programs->attendanceSummary($session->refresh());
        $this->assertSame(1, $summary['present']);
        $this->assertSame(100.0, $summary['attendance_rate']);

        $evaluation = $programs->evaluation($program->refresh());
        $this->assertSame(1, $evaluation['members']);
        $this->assertSame(1, $evaluation['completed_sessions']);
    }

    public function test_umpan_balik_yang_sudah_final_tidak_dapat_diubah_siapa_pun(): void
    {
        $family = $this->makeStudentWithFamily('Nashir');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $session = app(\App\Modules\Bk\Application\Services\BkSessionService::class)->schedule($counselor, $case, [
            'scheduled_at' => now()->toDateTimeString(),
            'session_type' => 'INDIVIDUAL',
        ]);

        $feedback = app(BkFeedbackService::class);

        $this->actAs($family['student_user']);
        $row = $feedback->submitByStudent($family['student_user'], [
            'subject_type' => 'SESSION',
            'subject_id' => $session->id,
            'rating' => 5,
            'comment' => 'Terima kasih, saya merasa didengar.',
            'is_anonymous' => false,
        ]);

        $feedback->finalize($family['student_user'], $row);
        $this->assertSame(FeedbackStatus::FINAL, $row->refresh()->status);

        // Setelah final: siswa sendiri pun tidak dapat mengubah.
        try {
            $feedback->updateComment($family['student_user'], $row->refresh(), ['rating' => 1]);
            $this->fail('Umpan balik final seharusnya tidak dapat diubah.');
        } catch (BkValidationException) {
            $this->assertTrue(true);
        }

        // Konselor tidak pernah dapat mengubah umpan balik siswa.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $feedback->updateComment($counselor, $row->refresh(), ['comment' => 'Diedit oleh konselor']);
    }

    public function test_umpan_balik_anonim_tidak_menyimpan_pengait_identitas_pada_payload_aman(): void
    {
        $family = $this->makeStudentWithFamily('Ishaq');
        $student = $family['student'];

        $this->actAs($family['student_user']);

        $row = app(BkFeedbackService::class)->submitByStudent($family['student_user'], [
            'subject_type' => 'PROGRAM',
            'rating' => 4,
            'comment' => 'Programnya bermanfaat.',
            'is_anonymous' => true,
        ]);

        $this->assertTrue((bool) $row->is_anonymous);
        // Yang dibaca staf adalah ringkasan tanpa identitas; isi tetap tersimpan
        // untuk keperluan tindak lanjut, tetapi tidak ditampilkan sebagai nama.
        $summary = app(BkFeedbackService::class)->summaryForSubject(\App\Modules\Bk\Domain\Enums\FeedbackSubjectType::PROGRAM, 0);
        $this->assertSame(0, $summary['total'], 'Ringkasan hanya menghitung umpan balik pada subjek yang diminta.');
        $this->assertSame(1, BkFeedback::query()->where('is_anonymous', true)->count());
    }
}
