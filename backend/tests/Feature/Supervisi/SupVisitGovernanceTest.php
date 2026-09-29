<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Models\SupDispute;
use App\Modules\Supervisi\Models\SupVisit;

/**
 * Tata kelola pelaksanaan: siapa boleh melihat, mengisi, memfinalkan,
 * menandai hasil, mengajukan keberatan, dan apa yang terjadi setelahnya.
 */
class SupVisitGovernanceTest extends SupTestCase
{
    /** @return array{0: Employee, 1: User, 2: Employee, 3: User, 4: array<string,mixed>} */
    protected function scenario(string $kind = 'KEPALA_SEKOLAH'): array
    {
        $supervisor = $this->employee(['full_name' => 'Pak Supervisor', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $supervisor, 'kind' => $kind, 'can_finalize' => true]);
        $supervisorUser = $this->employeeWithAccount($supervisor, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Bu Guru']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$supervisor, $supervisorUser, $teacher, $teacherUser, $this->publishedInstrument()];
    }

    /** Buat kegiatan lalu isi seluruh indikator wajib. */
    protected function makeFilledVisit(array $scenario, string $activityKind = 'WALKTHROUGH'): int
    {
        [, $supervisorUser, $teacher, , $instrument] = $scenario;

        $visitId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
            'activity_kind' => $activityKind,
        ])->assertCreated()->json('data.visit.id');

        $version = $instrument['version']->load('sections.indicators.options');
        $scale = $version->indicators->first(fn ($i) => $i->input_type->value === 'SCALE');
        $number = $version->indicators->first(fn ($i) => $i->input_type->value === 'NUMBER');
        $boolean = $version->indicators->first(fn ($i) => $i->input_type->value === 'BOOLEAN');
        $text = $version->indicators->first(fn ($i) => $i->input_type->value === 'LONG_TEXT');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", [
                'answers' => [
                    ['indicator_id' => $scale->id, 'option_id' => $scale->options->firstWhere('score', 3.0)->id],
                    ['indicator_id' => $number->id, 'value_number' => 7],
                    ['indicator_id' => $boolean->id, 'value_text' => 'YES'],
                    ['indicator_id' => $text->id, 'value_long_text' => 'Diskusi kelompok berjalan aktif.'],
                ],
            ])
            ->assertOk();

        return (int) $visitId;
    }

    /** Finalisasi kegiatan sampai selesai (untuk menguji tanda tangan & keberatan). */
    protected function finalize(array $scenario, int $visitId): void
    {
        [, $supervisorUser] = $scenario;

        // Nilai contoh 73,33 (< 80) sehingga temuan wajib diisi lebih dulu.
        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/findings", [
                'findings' => [[
                    'component' => 'PELAKSANAAN',
                    'priority' => 'MEDIUM',
                    'weakness' => 'Pertanyaan pemantik belum bertingkat.',
                    'recommendation' => 'Susun daftar pertanyaan HOTS untuk dua pertemuan berikutnya.',
                ]],
            ])
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertOk();
    }

    public function test_guru_hanya_melihat_kegiatan_miliknya(): void
    {
        $scenario = $this->scenario();
        $visitId = $this->makeFilledVisit($scenario);

        // Guru lain sama sekali tidak melihat kegiatan tersebut.
        $lain = $this->employee(['full_name' => 'Pak Guru Lain']);
        $lainUser = $this->employeeWithAccount($lain, 'guru');

        $list = $this->actingAsUser($lainUser)
            ->getJson('/api/v1/supervisi/visits')
            ->assertOk();

        $this->assertSame([], $list->json('data'));

        $this->actingAsUser($lainUser)
            ->getJson("/api/v1/supervisi/visits/{$visitId}")
            ->assertStatus(403);

        $this->actingAsUser($lainUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => []])
            ->assertStatus(403);
    }

    public function test_yang_disuervisi_melihat_hasil_miliknya_sendiri(): void
    {
        $scenario = $this->scenario();
        [, , , $teacherUser] = $scenario;
        $visitId = $this->makeFilledVisit($scenario);
        $this->finalize($scenario, $visitId);

        $detail = $this->actingAsUser($teacherUser)
            ->getJson("/api/v1/supervisi/visits/{$visitId}")
            ->assertOk();

        $this->assertTrue($detail->json('data.visit.is_finalized'));
        $this->assertNotNull($detail->json('data.summary.final'));
        $this->assertFalse($detail->json('data.abilities.execute'));
        $this->assertFalse($detail->json('data.abilities.finalize'));
        $this->assertTrue($detail->json('data.abilities.acknowledge'));
        $this->assertTrue($detail->json('data.acknowledgment.is_pending'));
        $this->assertNull($detail->json('data.acknowledgment.signature_hash')); // sidik jari hanya untuk tata kelola
    }

    public function test_rekan_sejawat_dan_yang_disuervisi_tidak_dapat_memfinalkan(): void
    {
        // Rekan sejawat: boleh mengisi, tidak boleh memfinalkan.
        $peerScenario = $this->scenario('PEER');
        $peerVisit = $this->makeFilledVisit($peerScenario);

        $this->actingAsUser($peerScenario[1])
            ->postJson("/api/v1/supervisi/visits/{$peerVisit}/finalize")
            ->assertStatus(403);

        $this->assertNull(SupVisit::query()->findOrFail($peerVisit)->finalized_at);

        // Yang disupervisi tidak dapat memfinalkan hasilnya sendiri.
        $scenario = $this->scenario();
        [, , , $teacherUser] = $scenario;
        $visitId = $this->makeFilledVisit($scenario);

        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")
            ->assertStatus(403);
    }

    public function test_kegiatan_hanya_dapat_dibuat_oleh_supervisor_terdaftar(): void
    {
        $teacher = $this->employee(['full_name' => 'Bu Guru']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');
        $lain = $this->employee(['full_name' => 'Pak Guru Lain']);
        $instrument = $this->publishedInstrument();

        $this->actingAsUser($teacherUser)
            ->postJson('/api/v1/supervisi/visits', [
                'supervisee_employee_id' => $lain->id,
                'instrument_version_id' => $instrument['version']->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supervisor_employee_id');
    }

    public function test_tanda_tangan_hasil_dan_keberatan_sampai_keputusan_moderator(): void
    {
        $scenario = $this->scenario();
        [$supervisor, $supervisorUser, $teacher, $teacherUser] = $scenario;
        $visitId = $this->makeFilledVisit($scenario);
        $this->finalize($scenario, $visitId);

        // 1) Belum ditandai → keberatan ditolak.
        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/dispute", ['reason' => 'Skor indikator 1 terlalu rendah.'])
            ->assertStatus(422);

        // 2) Tandai hasil.
        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/acknowledge", ['note' => 'Terima kasih atas masukannya.'])
            ->assertOk();

        $this->assertNotNull(SupVisit::query()->findOrFail($visitId)->acknowledgments()->first()->signature_hash);

        // Menandai dua kali tidak diizinkan.
        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/acknowledge")
            ->assertStatus(422);

        // 3) Guru tidak boleh memutuskan keberatannya sendiri.
        $disputeId = $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/dispute", [
                'reason' => 'Indikator pengelolaan kelas belum mencerminkan kondisi sebenarnya.',
                'proposed_scores' => [['indicator_id' => 1, 'score' => 4]],
            ])
            ->assertCreated()
            ->json('data.id');

        $visit = SupVisit::query()->findOrFail($visitId);
        $this->assertSame('DISPUTED', $visit->status->value);
        $this->assertTrue($visit->is_disputed);

        $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/disputes/{$disputeId}/resolve", [
                'status' => 'ACCEPTED',
                'decision_note' => 'Saya terima.',
            ])
            ->assertStatus(403);

        // 4) Moderator menolak keberatan → hasil lama berlaku kembali.
        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/disputes/{$disputeId}/resolve", [
                'status' => 'REJECTED',
                'decision_note' => 'Bukti observasi mendukung skor yang diberikan.',
                'moderator_employee_id' => $supervisor->id,
            ])
            ->assertOk();

        $visit = $visit->refresh();
        $this->assertSame('COMPLETED', $visit->status->value);
        $this->assertFalse($visit->is_disputed);

        // 5) Keberatan kedua yang diterima → dinilai ulang.
        $secondId = $this->actingAsUser($teacherUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/dispute", ['reason' => 'Ada bukti tambahan berupa video pembelajaran.'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/disputes/{$secondId}/resolve", [
                'status' => 'ACCEPTED',
                'decision_note' => 'Bukti tambahan diterima, nilai dihitung ulang.',
            ])
            ->assertOk();

        $visit = $visit->refresh();
        $this->assertSame('ANALYZED', $visit->status->value);
        $this->assertFalse($visit->is_disputed);
        $this->assertSame('ACCEPTED', SupDispute::query()->findOrFail($secondId)->status);
    }

    public function test_pembatalan_dan_penghapusan_draf(): void
    {
        $scenario = $this->scenario();
        [, $supervisorUser, $teacher, , $instrument] = $scenario;

        // Draf dapat dihapus.
        $draftId = $this->actingAsUser($supervisorUser)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $teacher->id,
            'instrument_version_id' => $instrument['version']->id,
        ])->assertCreated()->json('data.visit.id');

        $this->actingAsUser($supervisorUser)
            ->deleteJson("/api/v1/supervisi/visits/{$draftId}")
            ->assertOk();

        $this->assertSoftDeleted('sup_visits', ['id' => $draftId]);

        // Kegiatan yang sudah diisi & dikirim tidak dapat dihapus, hanya dibatalkan.
        $visitId = $this->makeFilledVisit($scenario);

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/submit")
            ->assertOk();

        $this->actingAsUser($supervisorUser)
            ->deleteJson("/api/v1/supervisi/visits/{$visitId}")
            ->assertStatus(422);

        $this->actingAsUser($supervisorUser)
            ->postJson("/api/v1/supervisi/visits/{$visitId}/cancel", ['reason' => 'Guru sakit, kegiatan dijadwalkan ulang.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->actingAsUser($supervisorUser)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => []])
            ->assertStatus(403);
    }

    public function test_tata_kelola_sekolah_melihat_seluruh_kegiatan(): void
    {
        $scenario = $this->scenario();
        $visitId = $this->makeFilledVisit($scenario);

        // Kepala sekolah lain (pemegang izin tata kelola) dapat memantau.
        $kepala = $this->employee(['full_name' => 'Bu Kepala Sekolah']);
        $kepalaUser = $this->employeeWithAccount($kepala, 'kepala-sekolah');

        $detail = $this->actingAsUser($kepalaUser)
            ->getJson("/api/v1/supervisi/visits/{$visitId}")
            ->assertOk();

        $this->assertTrue($detail->json('data.abilities.view_all'));
        $this->assertFalse($detail->json('data.abilities.execute'));
    }
}
