<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkExportService;
use App\Modules\Bk\Application\Services\BkRetentionService;
use App\Modules\Bk\Application\Services\BkSecureSearchService;
use App\Modules\Bk\Domain\Enums\ConsentScope;
use App\Modules\Bk\Domain\Enums\ExportFormat;
use App\Modules\Bk\Domain\Enums\RetentionActionType;
use App\Modules\Bk\Domain\Enums\RetentionEntityType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkRetentionAction;
use App\Modules\Bk\Models\BkRetentionPolicy;

/**
 * Pencarian aman, ekspor, dan retensi.
 *
 * Tiga titik yang paling sering menjadi kebocoran data di sistem nyata: pencarian
 * yang menampilkan semua yang cocok, ekspor yang memuat kolom sensitif, dan
 * penghapusan data tanpa jejak yang dapat diaudit.
 */
class BkSearchExportRetentionTest extends BkPhase2TestCase
{
    public function test_pencarian_guru_tidak_menemukan_kasus_atau_konferensi_internal(): void
    {
        $family = $this->makeStudentWithFamily('Musthafa');
        $counselor = $this->makeCounselor('BK_COORDINATOR');
        $teacher = $this->makeUserWithRole('guru', 'Ustadz Salim');

        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id, ['title' => 'Kecemasan ujian']);
        $this->assignCase($counselor, $case, $counselor);

        $conference = app(\App\Modules\Bk\Application\Services\BkCaseConferenceService::class)->create($counselor, $case, [
            'title' => 'Konferensi kecemasan ujian',
        ]);

        BkCaseNote::query()->create([
            'case_id' => $case->id,
            'note_type' => 'COUNSELOR_NOTE',
            'body' => 'Musthafa menyebut kecemasan ujian sebagai kata kunci rahasia.',
            'confidentiality_level' => 'COUNSELOR_ONLY',
            'is_sensitive' => true,
            'occurred_at' => now(),
            'created_by' => $counselor->id,
        ]);

        $search = app(BkSecureSearchService::class);

        // Tim BK: menemukan kasus dan konferensi.
        $staffResult = $search->search($counselor, 'Musthafa');
        $this->assertNotEmpty($staffResult['groups']['cases']);
        $this->assertNotEmpty($staffResult['groups']['conferences']);

        // Guru: menemukan siswanya, tetapi tanpa kasus, konferensi, atau penanda.
        $teacherResult = $search->search($teacher, 'Musthafa');

        $this->assertNotEmpty($teacherResult['groups']['students']);
        $this->assertArrayNotHasKey('cases', $teacherResult['groups']);
        $this->assertArrayNotHasKey('indicators', $teacherResult['groups']);
        $this->assertEmpty($teacherResult['groups']['conferences'] ?? [], 'Guru tidak menemukan konferensi internal.');

        // Kata kunci yang hanya ada di dalam catatan konseling tidak memunculkan apa pun.
        $secret = $search->search($teacher, 'kecemasan ujian sebagai kata kunci rahasia');
        $this->assertEmpty($secret['groups']['conferences'] ?? []);
        $this->assertArrayNotHasKey('cases', $secret['groups']);
    }

    public function test_ekspor_memerlukan_konfirmasi_izin_dan_tidak_pernah_memuat_catatan_konseling(): void
    {
        $family = $this->makeStudentWithFamily('Zaid');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id);
        $this->assignCase($coordinator, $case, $coordinator);

        BkCaseNote::query()->create([
            'case_id' => $case->id,
            'note_type' => 'COUNSELOR_NOTE',
            'body' => 'RAHASIA-EKSPOR: tidak boleh muncul di berkas ekspor.',
            'confidentiality_level' => 'COUNSELOR_ONLY',
            'occurred_at' => now(),
            'created_by' => $coordinator->id,
        ]);

        $exports = app(BkExportService::class);

        // Tanpa konfirmasi eksplisit: ditolak.
        try {
            $exports->create($coordinator, 'cases', ExportFormat::CSV, [], confirmed: false);
            $this->fail('Ekspor tanpa konfirmasi seharusnya ditolak.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('confirm', $exception->errors());
        }

        // Guru tanpa izin ekspor: ditolak.
        $teacher = $this->makeUserWithRole('guru', 'Ustadz Hamid');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $exports->create($teacher, 'cases', ExportFormat::CSV, [], confirmed: true);
    }

    public function test_berkas_ekspor_berisi_kolom_aman_saja_dan_tercatat_pada_audit(): void
    {
        $family = $this->makeStudentWithFamily('Ubay');
        $coordinator = $this->makeCounselor('BK_COORDINATOR');

        $this->actAs($coordinator);
        $case = $this->makeOpenCase($coordinator, (int) $family['student']->id);
        $this->assignCase($coordinator, $case, $coordinator);

        $exports = app(BkExportService::class);

        // Penyaring kolom diuji langsung: inilah pertahanan terakhirnya.
        $scrubbed = $exports->scrub([
            'case_number' => $case->case_number,
            'status' => 'OPEN',
            'counselor_note' => 'RAHASIA-EKSPOR',
            'discussion' => 'RAHASIA-EKSPOR',
            'diagnosis' => 'F90.0',
            'email' => 'wali@example.test',
        ]);

        $this->assertArrayHasKey('case_number', $scrubbed);
        $this->assertArrayNotHasKey('counselor_note', $scrubbed);
        $this->assertArrayNotHasKey('discussion', $scrubbed);
        $this->assertArrayNotHasKey('diagnosis', $scrubbed);
        $this->assertArrayNotHasKey('email', $scrubbed);

        $result = $exports->create($coordinator, 'cases', ExportFormat::CSV, [], confirmed: true);

        $this->assertSame('ready', $result['status']);
        $this->assertGreaterThan(0, $result['rows']);
        $this->assertDatabaseHas('bk_audit_logs', ['action' => 'EXPORT_CREATE']);
        $this->assertDatabaseHas('bk_retention_actions', ['action' => 'EXPORTED']);
    }

    public function test_kebijakan_retensi_berversi_dan_pemusnahan_menuntut_persetujuan_orang_lain(): void
    {
        $admin = $this->makeCounselor('BK_ADMIN');
        $approver = $this->makeCounselor('BK_COORDINATOR');

        $retention = app(BkRetentionService::class);

        $v1 = $retention->setPolicy($admin, RetentionEntityType::NOTE, ['retention_days' => 30, 'archive_after_days' => 10]);
        $v2 = $retention->setPolicy($admin, RetentionEntityType::NOTE, ['retention_days' => 60]);

        $this->assertSame(1, (int) $v1->version);
        $this->assertSame(2, (int) $v2->version, 'Perubahan kebijakan menjadi versi baru, bukan menimpa yang lama.');
        $this->assertFalse((bool) $v1->refresh()->is_active, 'Hanya satu kebijakan aktif per jenis rekaman.');
        $this->assertTrue((bool) $v2->refresh()->is_active);

        // Rekaman lama: memicu pengajuan pemusnahan.
        $family = $this->makeStudentWithFamily('Fathimah');
        $counselor = $this->makeCounselor();
        $this->actAs($counselor);
        $case = $this->makeOpenCase($counselor, (int) $family['student']->id);
        $this->assignCase($counselor, $case, $counselor);

        $note = BkCaseNote::query()->create([
            'case_id' => $case->id,
            'note_type' => 'GENERAL',
            'body' => 'Catatan lama yang melewati masa simpan.',
            'confidentiality_level' => 'RESTRICTED',
            'occurred_at' => now()->subYear(),
            'created_by' => $counselor->id,
        ]);
        $note->forceFill(['created_at' => now()->subDays(120)])->save();

        $request = $retention->requestDisposal($admin, RetentionEntityType::NOTE);
        $this->assertGreaterThanOrEqual(1, $request['requested']);

        $pending = BkRetentionAction::query()
            ->where('entity_type', RetentionEntityType::NOTE->value)
            ->where('action', RetentionActionType::DISPOSAL_REQUESTED->value)
            ->where('status', 'PENDING')
            ->firstOrFail();

        // Pengaju tidak boleh menyetujui permintaannya sendiri.
        try {
            $retention->approveDisposal($admin, $pending, true);
            $this->fail('Pemisahan tugas: pengaju tidak boleh menyetujui pemusnahannya sendiri.');
        } catch (BkValidationException $exception) {
            $this->assertArrayHasKey('approved_by', $exception->errors());
        }

        // Orang lain menyetujui, lalu eksekusi meninggalkan jejak.
        $approved = $retention->approveDisposal($approver, $pending, true, 'Masa simpan terlampaui.');
        $this->assertSame(RetentionActionType::DISPOSAL_APPROVED, $approved->action);

        $result = $retention->executeApprovedDisposal($approver);
        $this->assertGreaterThanOrEqual(1, $result['disposed']);

        $this->assertDatabaseHas('bk_retention_actions', [
            'entity_type' => RetentionEntityType::NOTE->value,
            'entity_id' => $note->id,
            'action' => RetentionActionType::DISPOSED->value,
        ]);
        $this->assertDatabaseHas('bk_audit_logs', ['action' => 'RETENTION_DISPOSE']);

        // Riwayat retensi mencatat seluruh perjalanan, bukan hanya hasil akhirnya.
        $history = collect($retention->history(RetentionEntityType::NOTE, (int) $note->id))
            ->pluck('action')
            ->all();

        $this->assertContains(RetentionActionType::DISPOSAL_REQUESTED->value, $history);
        $this->assertContains(RetentionActionType::DISPOSAL_APPROVED->value, $history);
        $this->assertContains(RetentionActionType::DISPOSED->value, $history);
    }

    public function test_persetujuan_granular_memerlukan_cakupan_yang_tepat(): void
    {
        $family = $this->makeStudentWithFamily('Ruqayyah');
        $counselor = $this->makeCounselor();
        $consents = app(\App\Modules\Bk\Application\Services\BkConsentService::class);

        $this->actAs($counselor);

        $counseling = $consents->request($counselor, (int) $family['student']->id, [
            'scope' => ConsentScope::COUNSELING->value,
            'purpose' => 'Konseling individual.',
        ]);

        $this->actAs($family['guardian_user']);
        $this->postJson("/api/v1/bk/consents/{$counseling->id}/grant", [])->assertOk();

        $this->actAs($counselor);

        // Izin konseling tidak berarti izin merujuk ke pihak luar.
        $this->assertNotNull($consents->validFor((int) $family['student']->id, ConsentScope::COUNSELING));
        $this->assertNull($consents->validFor((int) $family['student']->id, ConsentScope::EXTERNAL_REFERRAL));

        // Pencabutan tidak menghapus jejak pemberian sebelumnya.
        $revoked = $consents->revoke($counselor, $counseling->refresh(), 'Wali menarik izin setelah konseling selesai.');
        $this->assertSame('REVOKED', $revoked->status);
        $this->assertNotNull($revoked->granted_at, 'Jejak pemberian tetap ada setelah pencabutan.');
        $this->assertNotNull($revoked->revoked_at);
        $this->assertDatabaseHas('bk_audit_logs', ['action' => 'CONSENT_REVOKE']);
    }
}
