<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\ReferralStatus;
use App\Modules\Bk\Domain\Enums\ScreeningOutcome;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseEvent;
use App\Modules\Bk\Models\BkReferral;

/**
 * Alur utama referral: diajukan → diterima → screening → kasus → penugasan.
 *
 * Yang diuji juga: guru **boleh** merujuk tetapi tidak boleh menerima/menyaring,
 * referral yang sudah selesai tidak dapat diubah lagi, dan setiap perpindahan
 * status meninggalkan jejak di timeline.
 */
class BkReferralLifecycleTest extends BkTestCase
{
    public function test_guru_dapat_mengajukan_referral_dan_timeline_tercatat(): void
    {
        $family = $this->makeStudentWithFamily('Aisyah');
        $teacher = $this->actingAsRole('guru');

        $response = $this->postJson('/api/v1/bk/referrals', [
            'student_id' => $family['student']->id,
            'source_type' => 'TEACHER',
            'category' => 'ACADEMIC',
            'priority' => 'NORMAL',
            'description' => 'Siswa terlihat murung dan tidak mengumpulkan tugas tiga minggu terakhir.',
        ]);

        $response->assertCreated();

        $referral = BkReferral::query()->firstOrFail();
        $this->assertSame(ReferralStatus::SUBMITTED, $referral->status);
        $this->assertStringStartsWith('BK-R-', $referral->referral_number);
        $this->assertDatabaseHas('bk_case_events', [
            'referral_id' => $referral->id,
            'event_type' => 'REFERRAL_CREATED',
        ]);
    }

    public function test_guru_tidak_dapat_menerima_atau_menyaring_referral(): void
    {
        $family = $this->makeStudentWithFamily('Bilal');
        $counselor = $this->makeCounselor();

        $referral = $this->makeReferral($counselor, $family['student']);

        $this->actingAsRole('guru');

        $this->postJson("/api/v1/bk/referrals/{$referral->id}/receive")->assertForbidden();
        $this->postJson("/api/v1/bk/referrals/{$referral->id}/screen", [
            'presenting_concern' => 'Menurunnya nilai',
            'recommended_outcome' => 'COUNSELING',
        ])->assertForbidden();
    }

    public function test_koordinator_menerima_menyaring_dan_membuka_kasus(): void
    {
        $family = $this->makeStudentWithFamily('Hafiz');
        $coordinator = $this->actingAsRole('BK_COORDINATOR');

        $referral = $this->makeReferral($coordinator, $family['student']);

        $this->postJson("/api/v1/bk/referrals/{$referral->id}/receive")->assertOk();
        $this->assertSame(ReferralStatus::RECEIVED, $referral->refresh()->status);

        $screening = $this->postJson("/api/v1/bk/referrals/{$referral->id}/screen", [
            'presenting_concern' => 'Kehilangan minat belajar setelah pindah kelas.',
            'student_perspective' => 'Merasa materi terlalu cepat.',
            'recommended_outcome' => ScreeningOutcome::COUNSELING->value,
            'immediate_support_needed' => false,
        ])->assertCreated();

        $this->assertSame(1, \App\Modules\Bk\Models\BkScreening::query()->count());
        $this->assertNotNull($screening->json('data.academic_indicators'));

        $case = $this->postJson("/api/v1/bk/referrals/{$referral->id}/open-case", [
            'case_type' => CaseType::ACADEMIC_SUPPORT->value,
        ])->assertCreated();

        $caseId = $case->json('data.id');

        $this->assertDatabaseHas('bk_cases', ['id' => $caseId, 'source_referral_id' => $referral->id]);
        $this->assertSame($caseId, $referral->refresh()->case_id);
        $this->assertSame(ReferralStatus::ASSIGNED, $referral->status);
    }

    public function test_referral_terminal_tidak_dapat_diubah(): void
    {
        $family = $this->makeStudentWithFamily('Salman');
        $coordinator = $this->actingAsRole('BK_COORDINATOR');
        $referral = $this->makeReferral($coordinator, $family['student']);

        $this->referralService()->reject($coordinator, $referral, 'Bukan ranah BK.');

        $this->expectException(BkValidationException::class);

        $this->referralService()->receive($coordinator, $referral->refresh());
    }

    public function test_siswa_hanya_dapat_mengajukan_referral_untuk_dirinya(): void
    {
        $minah = $this->makeStudentWithFamily('Minah');
        $other = $this->makeStudentWithFamily('Zubair');

        $this->actAs($minah['student_user']);

        $this->referralService()->create($minah['student_user'], [
            'student_id' => $minah['student']->id,
            'source_type' => 'SELF_REFERRAL',
            'description' => 'Saya ingin bercerita tentang kesulitan belajar saya.',
        ]);

        $this->assertSame(1, BkReferral::query()->count());

        $this->expectException(BkValidationException::class);

        $this->referralService()->create($minah['student_user'], [
            'student_id' => $other['student']->id,
            'source_type' => 'SELF_REFERRAL',
            'description' => 'Mencoba mengajukan untuk siswa lain.',
        ]);
    }

    public function test_daftar_referral_mengikuti_cakupan_pelapor(): void
    {
        $family = $this->makeStudentWithFamily('Umar');
        $other = $this->makeStudentWithFamily('Khadijah');
        $counselor = $this->makeCounselor();

        $this->makeReferral($counselor, $family['student']);
        $this->makeReferral($counselor, $other['student']);

        $this->actingAsRole('guru');

        $response = $this->getJson('/api/v1/bk/referrals')->assertOk();

        $this->assertCount(0, $response->json('data'), 'Guru tanpa keterkaitan tidak melihat referral siswa lain.');

        // Orang tua tidak memakai daftar internal: permukaannya adalah portal,
        // dan daftar referral sekolah memang bukan untuk wali.
        $this->actAs($family['guardian_user']);
        $this->getJson('/api/v1/bk/referrals')->assertForbidden();
        $this->getJson('/api/v1/bk/portal/summary')->assertOk();
    }

    public function test_kasus_mewarisi_kerahasiaan_referral_dan_tidak_dapat_dilonggarkan(): void
    {
        $family = $this->makeStudentWithFamily('Yusuf');
        $coordinator = $this->actingAsRole('BK_COORDINATOR');

        $referral = $this->makeReferral($coordinator, $family['student'], ['confidentiality_level' => 'COUNSELOR_ONLY']);
        $case = $this->referralService()->openCase($coordinator, $referral, ['case_type' => CaseType::INDIVIDUAL_COUNSELING->value]);

        $this->assertSame('COUNSELOR_ONLY', $case->confidentiality_level->value);

        $this->expectException(BkValidationException::class);

        $this->caseService()->update($coordinator, $case, ['confidentiality_level' => 'STAFF_ONLY']);
    }

    public function test_timeline_kasus_menuat_perjalanan_lengkap(): void
    {
        $family = $this->makeStudentWithFamily('Asma');
        $coordinator = $this->actingAsRole('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $referral = $this->makeReferral($coordinator, $family['student']);
        $this->referralService()->receive($coordinator, $referral);
        $case = $this->referralService()->openCase($coordinator, $referral, ['case_type' => CaseType::INDIVIDUAL_COUNSELING->value]);
        $this->assignCase($coordinator, $case, $counselor);

        $events = BkCaseEvent::query()->where('case_id', $case->id)->pluck('event_type')->map(fn ($e) => $e->value)->all();

        $this->assertContains('CASE_OPENED', $events);
        $this->assertContains('CASE_ASSIGNED', $events);

        $this->actAs($coordinator);
        $timeline = $this->getJson("/api/v1/bk/cases/{$case->id}/timeline")->assertOk()->json('data');

        $events = array_column($timeline, 'event');

        // Timeline kasus dimulai dari referral yang melahirkannya, lalu kasus.
        $this->assertSame('REFERRAL_CREATED', $events[0]);
        $this->assertContains('CASE_OPENED', $events);
        $this->assertContains('CASE_ASSIGNED', $events);
        $this->assertArrayHasKey('actor', $timeline[0]);
    }
}
