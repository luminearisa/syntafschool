<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkConsentService;
use App\Modules\Bk\Models\BkConsent;
use App\Modules\Bk\Models\BkSupportRequest;

/**
 * Portal siswa & orang tua.
 *
 * Yang dijaga: siswa mengajukan untuk dirinya sendiri, wali hanya untuk anak yang
 * tertaut sah, persetujuan hanya dapat diputuskan wali yang benar, dan portal
 * tidak pernah mengembalikan isi konseling.
 */
class BkPortalTest extends BkTestCase
{
    public function test_siswa_dapat_mengajukan_permintaan_konseling_tanpa_menulis_detail_sensitif(): void
    {
        $family = $this->makeStudentWithFamily('Aqila');
        $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($family['student_user']);

        $this->postJson('/api/v1/bk/portal/requests/student', [
            'topic' => 'Ingin bercerita',
            'preferred_time_slot' => 'Istirahat pertama',
        ])->assertCreated();

        $request = BkSupportRequest::query()->firstOrFail();

        $this->assertSame('QUEUED', $request->status->value);
        $this->assertSame('STUDENT_SELF', $request->request_type->value);
        $this->assertNull($request->message, 'Detail sensitif tidak diwajibkan pada formulir awal.');
        $this->assertDatabaseHas('bk_notification_logs', ['event' => 'NEW_REFERRAL']);
    }

    public function test_orang_tua_hanya_dapat_mengajukan_untuk_anaknya(): void
    {
        $family = $this->makeStudentWithFamily('Barirah');
        $other = $this->makeStudentWithFamily('Tsabit');

        $this->actAs($family['guardian_user']);

        $this->postJson('/api/v1/bk/portal/requests/guardian', [
            'student_id' => $family['student']->id,
            'topic' => 'Kesulitan tidur',
        ])->assertCreated();

        $this->postJson('/api/v1/bk/portal/requests/guardian', [
            'student_id' => $other['student']->id,
            'topic' => 'Mencoba mengajukan untuk anak orang lain',
        ])->assertStatus(422);
    }

    public function test_permintaan_portal_ditelaah_menjadi_referral(): void
    {
        $family = $this->makeStudentWithFamily('Cahya');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($family['guardian_user']);
        $this->postJson('/api/v1/bk/portal/requests/guardian', [
            'student_id' => $family['student']->id,
            'topic' => 'Perubahan perilaku di rumah',
            'urgent' => true,
        ])->assertCreated();

        $request = BkSupportRequest::query()->firstOrFail();

        $reviewed = app(\App\Modules\Bk\Application\Services\BkSupportRequestService::class)
            ->review($coordinator, $request, ['decision' => 'ASSIGNED']);

        $this->assertNotNull($reviewed->referral_id);
        $this->assertDatabaseHas('bk_referrals', ['id' => $reviewed->referral_id, 'source_type' => 'PARENT', 'priority' => 'HIGH']);
    }

    public function test_persetujuan_hanya_dapat_diputuskan_wali_sah(): void
    {
        $family = $this->makeStudentWithFamily('Dzakir');
        $other = $this->makeStudentWithFamily('Sultan');
        $counselor = $this->makeCounselor();

        $consent = app(BkConsentService::class)->request($counselor, $family['student']->id, [
            'scope' => 'COUNSELING',
            'purpose' => 'Konseling individual lanjutan.',
        ]);

        // Wali lain ditolak.
        $this->actAs($other['guardian_user']);
        $this->postJson("/api/v1/bk/portal/consents/{$consent->id}/decide", ['decision' => 'APPROVED'])->assertStatus(422);

        // Wali sah berhasil.
        $this->actAs($family['guardian_user']);
        $this->postJson("/api/v1/bk/portal/consents/{$consent->id}/decide", [
            'decision' => 'APPROVED',
            'note' => 'Silakan didampingi.',
        ])->assertOk();

        $this->assertSame('APPROVED', $consent->refresh()->status);
        $this->assertTrue(app(BkConsentService::class)->hasApproval($family['student']->id));
        $this->assertDatabaseHas('bk_audit_logs', ['action' => 'CONSENT_DECIDE']);
    }

    public function test_persetujuan_kedaluwarsa_ditutup_perintah_terjadwal(): void
    {
        $family = $this->makeStudentWithFamily('Rafiqa');
        $counselor = $this->makeCounselor();

        $consent = BkConsent::query()->create([
            'consent_number' => 'BK-CS-TEST-1',
            'student_id' => $family['student']->id,
            'requested_by' => $counselor->id,
            'scope' => 'SHARING',
            'status' => 'APPROVED',
            'requested_at' => now()->subMonth(),
            'decided_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('bk:expire-consents')->assertSuccessful();

        $this->assertSame('EXPIRED', $consent->refresh()->status);
    }

    public function test_portal_orang_tua_tidak_memuat_tujuan_sesi_konseling(): void
    {
        $family = $this->makeStudentWithFamily('Naila');
        $counselor = $this->makeCounselor();

        $this->actAs($counselor);
        $case = $this->makeCase($counselor, $family['student']);
        $this->assignCase($counselor, $case, $counselor);

        app(\App\Modules\Bk\Application\Services\BkAppointmentService::class)->create($counselor, [
            'student_id' => $family['student']->id,
            'case_id' => $case->id,
            'counselor_id' => $counselor->id,
            'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'scheduled_end' => now()->addDay()->setTime(9, 45)->toDateTimeString(),
            'purpose' => 'RAHASIA-KONSELOR: membahas dugaan kekerasan di rumah.',
        ]);

        $this->actAs($family['guardian_user']);
        $response = $this->getJson('/api/v1/bk/portal/appointments')->assertOk();

        $this->assertStringNotContainsString('RAHASIA-KONSELOR', $response->getContent());
        $this->assertNotEmpty($response->json('data'));
    }
}
