<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkCaseConferenceService;
use App\Modules\Bk\Application\Services\BkExternalReferralService;
use App\Modules\Bk\Domain\Enums\ConferenceActionStatus;
use App\Modules\Bk\Domain\Enums\ConferenceStatus;
use App\Modules\Bk\Domain\Enums\ConsentScope;
use App\Modules\Bk\Domain\Enums\ConsentStatus;
use App\Modules\Bk\Domain\Enums\ExternalReferralStatus;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCaseConferenceAction;

/**
 * Konferensi kasus & rujukan eksternal.
 *
 * Fokusnya dua aturan yang paling mudah dilanggar dalam praktik: konferensi tanpa
 * tindak lanjut bertanggung jawab, dan data siswa yang keluar tanpa persetujuan.
 */
class BkConferenceExternalReferralTest extends BkPhase2TestCase
{
    public function test_konferensi_wajib_berakhir_dengan_tindak_lanjut_bertanggung_jawab(): void
    {
        $family = $this->makeStudentWithFamily('Ali');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id);
        $this->assignCase($coordinator, $case, $counselor);

        $service = app(BkCaseConferenceService::class);
        $conference = $service->create($coordinator, $case, [
            'title' => 'Konferensi dukungan belajar',
            'purpose' => 'Menyepakati bentuk dukungan.',
            'scheduled_start' => now()->addDay()->toDateTimeString(),
        ]);

        $this->assertSame(ConferenceStatus::SCHEDULED, $conference->status);

        // Keputusan kosong: ditolak.
        try {
            $service->complete($coordinator, $conference, []);
            $this->fail('Konferensi tanpa keputusan seharusnya ditolak.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }

        // Tindak lanjut tanpa penanggung jawab: ditolak.
        try {
            $service->createAction($coordinator, $conference, [
                'title' => 'Pendampingan belajar',
                'due_date' => now()->addWeek()->toDateString(),
            ]);
            $this->fail('Tindak lanjut tanpa penanggung jawab seharusnya ditolak.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('responsible_user_id', $exception->errors());
        }

        // Dengan penanggung jawab dan tenggat: dibuat, dan ikut terpantau di kasus.
        $action = $service->createAction($coordinator, $conference, [
            'title' => 'Pendampingan belajar dua pekan',
            'responsible_user_id' => $counselor->id,
            'due_date' => now()->addWeek()->toDateString(),
            'create_case_follow_up' => true,
        ]);

        $this->assertSame(ConferenceActionStatus::PENDING, $action->status);
        $this->assertNotNull($action->follow_up_id, 'Tindak lanjut konferensi ikut tercatat pada kasus.');
        $this->assertDatabaseHas('bk_follow_ups', ['id' => $action->follow_up_id]);

        $completed = $service->complete($coordinator, $conference, [
            'decision' => 'Dukungan belajar intensif selama dua pekan.',
            'decision_outcome' => 'INTERVENTION',
            'follow_up_required' => true,
            'follow_up_summary' => 'Pendampingan belajar oleh konselor.',
        ]);

        $this->assertSame(ConferenceStatus::COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_rujukan_eksternal_tidak_dapat_dikirim_tanpa_persetujuan_wali(): void
    {
        $family = $this->makeStudentWithFamily('Maryam');
        $counselor = $this->makeCounselor();
        $provider = $this->makeProvider();

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $service = app(BkExternalReferralService::class);

        $referral = $service->create($counselor, [
            'student_id' => $family['student']->id,
            'case_id' => $case->id,
            'referral_provider_id' => $provider->id,
            'reason' => 'Memerlukan asesmen psikolog.',
        ]);

        $this->assertSame(ExternalReferralStatus::DRAFT, $referral->status);

        $service->recordAssessment($counselor, $referral, 'Asesmen internal menunjukkan kebutuhan asesmen lanjutan.');
        $this->assertSame(ExternalReferralStatus::ASSESSMENT, $referral->refresh()->status);

        // Tanpa persetujuan: pengiriman ditolak.
        try {
            $service->submit($counselor, $referral->refresh());
            $this->fail('Rujukan tanpa persetujuan wali seharusnya ditolak.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('consent_id', $exception->errors());
        }

        // Permintaan persetujuan dibuat, tetapi belum diberikan: masih ditolak.
        $referral = $service->requestConsent($counselor, $referral->refresh());
        $this->assertSame(ExternalReferralStatus::PENDING_CONSENT, $referral->status);
        $this->assertSame(ConsentStatus::REQUESTED->value, $referral->consent->status);

        try {
            $service->submit($counselor, $referral->refresh());
            $this->fail('Persetujuan yang masih diminta belum cukup untuk mengirim data.');
        } catch (BkValidationException) {
            $this->assertTrue(true);
        }

        // Wali memberikan persetujuan: sekarang boleh dikirim.
        $this->actAs($family['guardian_user']);
        $this->postJson("/api/v1/bk/consents/{$referral->consent_id}/grant", ['note' => 'Setuju untuk asesmen.'])->assertOk();

        $this->actAs($counselor);
        $submitted = $service->submit($counselor, $referral->refresh());

        $this->assertSame(ExternalReferralStatus::REFERRED, $submitted->status);
        $this->assertNotNull($submitted->referred_at);
        $this->assertSame(ConsentScope::EXTERNAL_REFERRAL->value, $submitted->consent->scope);
    }

    public function test_hasil_rujukan_tidak_menyimpan_data_medis(): void
    {
        $family = $this->makeStudentWithFamily('Ibrahim');
        $counselor = $this->makeCounselor();
        $provider = $this->makeProvider('Klinik Mitra', 'DOCTOR');

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $service = app(BkExternalReferralService::class);
        $referral = $service->create($counselor, [
            'student_id' => $family['student']->id,
            'case_id' => $case->id,
            'referral_provider_id' => $provider->id,
        ]);

        $service->requestConsent($counselor, $referral->refresh());

        try {
            $service->recordResult($counselor, $referral->refresh(), [
                'diagnosis' => 'F90.0',
                'prescription' => 'Methylphenidate 10mg',
            ]);
            $this->fail('Data medis seharusnya ditolak oleh modul BK.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
            $this->assertStringContainsString('Clinic/Health', implode(' ', $exception->errors()['outcome']));
        }

        // Ringkasan non-medis diterima, dan hanya itu yang tersimpan.
        $result = $service->recordResult($counselor, $referral->refresh(), [
            'outcome' => 'Perlu pendampingan belajar terstruktur.',
            'external_summary' => 'Rekomendasi pendampingan; rincian klinis ada pada penyedia.',
            'clinic_reference' => 'UKS-2026-000123',
            'outcome_shared_with_parent' => true,
        ]);

        $this->assertSame(ExternalReferralStatus::RESULT_RECEIVED, $result->status);
        $this->assertNull($result->getAttribute('diagnosis'));
        $this->assertSame('UKS-2026-000123', $result->clinic_reference);
    }

    public function test_riwayat_konferensi_menolak_tindak_lanjut_tanpa_tenggat(): void
    {
        $family = $this->makeStudentWithFamily('Yaqub');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id);
        $this->assignCase($coordinator, $case, $coordinator);

        $conference = app(BkCaseConferenceService::class)->create($coordinator, $case, [
            'title' => 'Konferensi tanpa tenggat',
        ]);

        try {
            app(BkCaseConferenceService::class)->createAction($coordinator, $conference, [
                'title' => 'Tindak lanjut tanpa tenggat',
                'responsible_user_id' => $coordinator->id,
            ]);
            $this->fail('Tindak lanjut tanpa tenggat seharusnya ditolak.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('due_date', $exception->errors());
        }

        $this->assertSame(0, BkCaseConferenceAction::query()->count());
    }
}
