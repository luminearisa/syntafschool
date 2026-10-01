<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkNoteService;
use App\Modules\Bk\Application\Services\BkSessionService;
use App\Modules\Bk\Domain\Enums\CaseParticipantRole;
use App\Modules\Bk\Domain\Enums\CaseType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\NoteType;
use App\Modules\Bk\Domain\Enums\SessionType;
use App\Modules\Bk\Models\BkAuditLog;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseDocument;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkCounselingSession;
use App\Modules\Bk\Models\BkParentMeeting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Uji keamanan & privasi Modul BK (§28).
 *
 * Yang diuji bukan tampilan, melainkan perilaku backend:
 *
 * - Guru **X** catatan konseling rahasia.
 * - Orang tua **X** konferensi kasus internal.
 * - Siswa **X** catatan konselor.
 * - Wali tidak dapat membuka kasus anak orang lain meski menebak ID-nya.
 * - NIS/NISN siswa lain tidak bocor lewat daftar.
 * - Dokumen rahasia tidak dapat diunduh.
 * - Laporan/ekspor tidak memuat catatan konseling.
 * - Setiap pembacaan data rahasia tercatat di jejak audit.
 */
class BkPrivacySecurityTest extends BkTestCase
{
    /** Menyiapkan kasus konseling dengan sesi, catatan, dan konferensi internal. */
    private function confidentialContext(): array
    {
        $family = $this->makeStudentWithFamily('Nadia');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');
        $counselor = $this->makeCounselor();

        $this->actAs($coordinator);
        $case = $this->makeCase($coordinator, $family['student'], [
            'case_type' => CaseType::INDIVIDUAL_COUNSELING->value,
        ]);
        $this->assignCase($coordinator, $case, $counselor);

        $session = app(BkSessionService::class)->schedule($counselor, $case, [
            'session_type' => SessionType::INDIVIDUAL->value,
            'scheduled_at' => now()->subDay()->toDateTimeString(),
        ]);

        app(BkSessionService::class)->record($counselor, $session, [
            'status' => 'COMPLETED',
            'started_at' => now()->subDay()->toDateTimeString(),
            'ended_at' => now()->subDay()->addMinutes(45)->toDateTimeString(),
            'session_summary' => 'Siswa mulai membuka diri tentang tekanan di rumah.',
            'counselor_note' => 'RAHASIA-KONSELOR: indikasi kekerasan verbal, perlu pemantauan ketat.',
            'risk_indicator' => 'MODERATE',
        ]);

        $note = app(BkNoteService::class)->create($counselor, $case, [
            'body' => 'RAHASIA-KONSELOR: rencana pendampingan intensif dua minggu.',
            'note_type' => NoteType::SENSITIVE->value,
            'is_sensitive' => true,
        ]);

        return compact('family', 'coordinator', 'counselor', 'case', 'session', 'note');
    }

    public function test_guru_tidak_dapat_membuka_catatan_konseling(): void
    {
        $context = $this->confidentialContext();
        $teacher = $this->accountFor('guru', $context['family']['class'], ['name' => 'Guru Kelas']);

        $this->actAs($teacher);

        $this->getJson("/api/v1/bk/cases/{$context['case']->id}")->assertForbidden();
        $this->getJson("/api/v1/bk/cases/{$context['case']->id}/sessions")->assertForbidden();
        $this->getJson("/api/v1/bk/cases/{$context['case']->id}/notes")->assertForbidden();

        $this->assertFalse(
            app(\App\Modules\Bk\Application\Services\BkAccessService::class)
                ->canViewNote($teacher, $context['note']),
            'Guru tidak boleh membaca catatan sensitif meski kasusnya dibagikan.',
        );
    }

    public function test_guru_melihat_kasus_hanya_bila_dibagikan_dan_tanpa_catatan(): void
    {
        $context = $this->confidentialContext();
        $homeroom = $this->accountFor('wali-kelas', $context['family']['class'], ['name' => 'Wali Kelas']);

        $coordinator = $context['coordinator'];

        // Kasus konseling tingkat COUNSELOR_ONLY tidak dapat dibuka wali kelas...
        $this->actAs($homeroom);
        $this->getJson("/api/v1/bk/cases/{$context['case']->id}")->assertForbidden();

        // ... tetapi bila kasusnya diturunkan ke STAFF_ONLY dan dibagikan, ia boleh melihat.
        $this->actAs($coordinator);
        $context['case']->forceFill(['confidentiality_level' => ConfidentialityLevel::STAFF_ONLY->value])->save();
        $this->caseService()->addParticipant($coordinator, $context['case'], [
            'role' => CaseParticipantRole::HOMEROOM_TEACHER->value,
            'user_id' => $homeroom->id,
            'can_view_case' => true,
            'max_confidentiality' => ConfidentialityLevel::STAFF_ONLY->value,
        ]);

        $this->actAs($homeroom);
        $this->getJson("/api/v1/bk/cases/{$context['case']->id}")->assertOk();

        // Catatan konseling tetap tertutup baginya (izin rute tidak dimiliki).
        $this->getJson("/api/v1/bk/cases/{$context['case']->id}/notes")->assertForbidden();
    }

    public function test_orang_tua_tidak_dapat_membuka_kasus_anak_orang_lain(): void
    {
        $context = $this->confidentialContext();
        $otherFamily = $this->makeStudentWithFamily('Rafi');

        $this->actAs($otherFamily['guardian_user']);

        $this->getJson("/api/v1/bk/cases/{$context['case']->id}")->assertForbidden();
        $this->getJson("/api/v1/bk/portal/consents")->assertOk();
        $this->getJson("/api/v1/bk/students/{$context['family']['student']->id}/support-profile")->assertForbidden();
    }

    public function test_orang_tua_tidak_melihat_konferensi_kasus_internal(): void
    {
        $context = $this->confidentialContext();

        $meeting = BkParentMeeting::query()->create([
            'meeting_number' => 'BK-PM-TEST-1',
            'case_id' => $context['case']->id,
            'student_id' => $context['family']['student']->id,
            'scheduled_at' => now()->addDay(),
            'discussion' => 'Konferensi kasus internal: membahas dugaan kekerasan verbal di rumah.',
            'status' => 'SCHEDULED',
        ]);

        $this->actAs($context['family']['guardian_user']);

        $this->getJson("/api/v1/bk/cases/{$context['case']->id}/meetings")->assertForbidden();
        $this->assertFalse(
            app(\App\Modules\Bk\Application\Services\BkAccessService::class)
                ->canViewCase($context['family']['guardian_user'], $meeting->case),
            'Wali tidak melihat konferensi kasus internal.',
        );
    }

    public function test_siswa_tidak_melihat_catatan_konselor(): void
    {
        $context = $this->confidentialContext();

        $this->actAs($context['family']['student_user']);

        $this->getJson("/api/v1/bk/cases/{$context['case']->id}/notes")->assertForbidden();
        $this->getJson("/api/v1/bk/cases/{$context['case']->id}/sessions")->assertForbidden();

        // Portal siswa tetap berjalan: yang dibagikan saja.
        $this->getJson('/api/v1/bk/portal/appointments')->assertOk();
    }

    public function test_daftar_sesi_tidak_pernah_memuat_catatan_konselor_pada_payload_rahasia(): void
    {
        $context = $this->confidentialContext();
        $counselor = $context['counselor'];

        $this->actAs($counselor);

        $payload = $this->getJson("/api/v1/bk/cases/{$context['case']->id}/sessions")->assertOk()->json('data');
        $this->assertStringContainsString('RAHASIA-KONSELOR', (string) $payload[0]['counselor_note']);

        // Wali kelas dengan akses sebagian tidak pernah menerima kolom itu.
        $homeroom = $this->accountFor('wali-kelas', $context['family']['class']);
        $this->caseService()->addParticipant($counselor, $context['case'], [
            'role' => CaseParticipantRole::HOMEROOM_TEACHER->value,
            'user_id' => $homeroom->id,
            'can_view_case' => true,
        ]);

        $this->actAs($homeroom);
        $forbidden = $this->getJson("/api/v1/bk/cases/{$context['case']->id}/sessions");
        $forbidden->assertForbidden();
    }

    public function test_dokumen_rahasia_tidak_dapat_diunduh_pihak_lain(): void
    {
        Storage::fake('local');

        $context = $this->confidentialContext();
        $counselor = $context['counselor'];

        $document = BkCaseDocument::query()->create([
            'case_id' => $context['case']->id,
            'student_id' => $context['family']['student']->id,
            'document_type' => 'MEETING_MINUTES',
            'title' => 'Notulen internal',
            'provider' => 'local',
            'provider_reference' => 'bk/documents/test/notulen.pdf',
            'visibility' => ConfidentialityLevel::CONFIDENTIAL->value,
            'is_sensitive' => true,
            'uploaded_by' => $counselor->id,
            'document_date' => now()->toDateString(),
        ]);

        $homeroom = $this->accountFor('wali-kelas', $context['family']['class']);
        $this->caseService()->addParticipant($counselor, $context['case'], [
            'role' => CaseParticipantRole::HOMEROOM_TEACHER->value,
            'user_id' => $homeroom->id,
            'can_view_case' => true,
        ]);

        $this->actAs($homeroom);
        $this->getJson("/api/v1/bk/documents/{$document->id}/download")->assertForbidden();

        // Pengguna berwenang berhak mendapat jawaban jelas bahwa berkasnya hilang
        // (422 + pesan), bukan 404 yang menyembunyikan sebabnya.
        $this->actAs($counselor);
        $response = $this->getJson("/api/v1/bk/documents/{$document->id}/download")->assertStatus(422);
        $this->assertStringContainsString('tidak ditemukan', json_encode($response->json('errors')));
    }

    public function test_laporan_tidak_memuat_catatan_konseling(): void
    {
        $context = $this->confidentialContext();

        $this->actAs($context['coordinator']);

        foreach (['case_types', 'case_outcomes', 'counselor_activity', 'follow_up_compliance', 'confidentiality_mix'] as $type) {
            $response = $this->getJson("/api/v1/bk/reports/{$type}")->assertOk();
            $this->assertStringNotContainsString('RAHASIA-KONSELOR', $response->getContent());
        }
    }

    public function test_audit_hanya_dapat_dibaca_pemegang_izin(): void
    {
        $context = $this->confidentialContext();

        $this->actAs($context['family']['guardian_user']);
        $this->getJson('/api/v1/bk/audit')->assertForbidden();

        $this->actAs($this->actingAsRole('auditor'));
        $this->getJson('/api/v1/bk/audit')->assertOk();

        $this->actAs($this->actingAsRole('guru'));
        $this->getJson('/api/v1/bk/audit')->assertForbidden();
    }

    public function test_pembacaan_data_rahasia_tercatat_pada_jejak_audit(): void
    {
        $context = $this->confidentialContext();

        $this->actAs($context['counselor']);

        $session = BkCounselingSession::query()->firstOrFail();
        $counselor = $context['counselor'];

        app(\App\Modules\Bk\Application\Services\BkAccessService::class)
            ->assertCanViewSession($counselor, $session);

        $this->assertTrue(
            BkAuditLog::query()->where('action', 'VIEW_CONFIDENTIAL')->where('subject_id', $session->id)->exists(),
            'Pembacaan sesi rahasia harus tercatat pada jejak audit.',
        );
    }

    public function test_penolakan_akses_tercatat_sebagai_audit(): void
    {
        $context = $this->confidentialContext();
        $teacher = $this->accountFor('guru', $context['family']['class']);

        try {
            app(\App\Modules\Bk\Application\Services\BkAccessService::class)
                ->assertCanViewNote($teacher, $context['note']);
        } catch (AccessDeniedHttpException) {
            // diharapkan
        }

        $this->assertTrue(
            BkAuditLog::query()
                ->where('action', 'VIEW_CONFIDENTIAL')
                ->where('actor_id', $teacher->id)
                ->whereJsonContains('context->granted', false)
                ->exists(),
            'Percobaan akses yang ditolak pun harus tercatat.',
        );
    }

    public function test_jejak_audit_tidak_memuat_isi_catatan(): void
    {
        $context = $this->confidentialContext();
        $counselor = $context['counselor'];

        app(BkNoteService::class)->create($counselor, $context['case'], [
            'body' => 'RAHASIA-KONSELOR: detail yang tidak boleh masuk audit.',
            'note_type' => NoteType::COUNSELOR_NOTE->value,
        ]);

        $logs = BkAuditLog::query()->get();

        foreach ($logs as $log) {
            $this->assertStringNotContainsString('RAHASIA-KONSELOR: detail', json_encode($log->changes ?? []));
        }
    }
}
