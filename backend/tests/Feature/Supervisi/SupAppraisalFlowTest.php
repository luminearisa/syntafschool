<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Database\Seeders\SupInstrumentSeeder;
use App\Modules\Supervisi\Models\SupAppraisal;

/**
 * Penilaian kinerja: PKG dari 56, sebutan & angka kredit, koreksi kehadiran,
 * tinjauan pegawai multi-sumber (360°), kuadran SKP, dan penguncian hasil.
 */
class SupAppraisalFlowTest extends SupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Penilaian kinerja memakai instrumen bawaan (PKG 14, PKG BK 17, NKKS 24).
        $this->seed(SupInstrumentSeeder::class);
    }

    /** @return array{0: Employee, 1: User, 2: Employee, 3: User} */
    protected function actors(): array
    {
        $assessor = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $assessorUser = $this->employeeWithAccount($assessor, 'kepala-sekolah');

        $teacher = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$assessor, $assessorUser, $teacher, $teacherUser];
    }

    /** @return array{0: int, 1: list<array<string,mixed>>} */
    protected function createAppraisal(User $assessorUser, Employee $teacher, Employee $assessor, string $type = 'PKG'): array
    {
        $response = $this->actingAsUser($assessorUser)->postJson('/api/v1/supervisi/appraisals', [
            'supervisee_employee_id' => $teacher->id,
            'appraisal_type' => $type,
            'period_type' => 'SEMESTER',
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->endOfYear()->toDateString(),
            'assessor_employee_id' => $assessor->id,
        ])->assertCreated();

        return [(int) $response->json('data.appraisal.id'), $response->json('data.indicators')];
    }

    public function test_pkg_guru_dihitung_dari_56_dengan_koreksi_kehadiran(): void
    {
        [$assessor, $assessorUser, $teacher] = $this->actors();

        [$appraisalId, $indicators] = $this->createAppraisal($assessorUser, $teacher, $assessor);

        $this->assertCount(14, $indicators, 'PKG guru mapel/kelas memakai 14 kompetensi.');

        // Seluruh kompetensi bernilai 4 → 56/56 → 100.
        $scores = array_map(fn (array $indicator) => [
            'indicator_id' => $indicator['id'],
            'score' => 4,
            'comment' => 'Terpenuhi dengan baik.',
        ], $indicators);

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", ['scores' => $scores])
            ->assertOk();

        $calculated = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate")
            ->assertOk();

        $this->assertEquals(56.0, $calculated->json('data.appraisal.raw_score'));
        $this->assertEquals(56.0, $calculated->json('data.appraisal.max_score'));
        $this->assertEquals(100.0, $calculated->json('data.appraisal.final_score'));
        $this->assertEquals('Amat Baik', $calculated->json('data.appraisal.grade_label'));
        $this->assertEquals(125.0, $calculated->json('data.appraisal.credit_percent'));
        $this->assertEquals('pkg-pembelajaran', $calculated->json('data.instrument.code'));

        // Koreksi kehadiran: 5 hari tanpa keterangan dari 46 hari kerja.
        $corrected = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate", [
                'attendance_absence_days' => 5,
            ])
            ->assertOk();

        $this->assertEquals(89.13, $corrected->json('data.appraisal.attendance_correction_percent'));
        $this->assertEquals(89.13, $corrected->json('data.appraisal.final_score'));
        $this->assertEquals('Baik', $corrected->json('data.appraisal.grade_label'));
        $this->assertEquals(100.0, $corrected->json('data.appraisal.credit_percent'));

        // Seluruh langkah tersimpan untuk audit.
        $steps = $corrected->json('data.appraisal.snapshot.steps');
        $this->assertEquals(56.0, $steps['raw']);
        $this->assertEquals(100.0, $steps['converted']);
        $this->assertEquals(89.13, $steps['attendance']['percent']);
        $this->assertEquals(89.13, $steps['after_attendance']);
        $this->assertEquals(5, $steps['attendance']['absence_days']);
    }

    public function test_skor_di_luar_batas_indikator_ditolak(): void
    {
        [$assessor, $assessorUser, $teacher] = $this->actors();
        [$appraisalId, $indicators] = $this->createAppraisal($assessorUser, $teacher, $assessor);

        $pertama = $indicators[0];

        // PKG memakai skala 1–4.
        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => [['indicator_id' => $pertama['id'], 'score' => 5]],
            ])
            ->assertStatus(422);

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => [['indicator_id' => $pertama['id'], 'score' => 0]],
            ])
            ->assertStatus(422);

        // Pilihan rubrik milik indikator lain tidak boleh dipakai.
        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => [[
                    'indicator_id' => $pertama['id'],
                    'option_id' => $indicators[1]['options'][0]['id'],
                ]],
            ])
            ->assertStatus(422);

        // Indikator di luar instrumen dokumen ini.
        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => [['indicator_id' => 999999, 'score' => 4]],
            ])
            ->assertStatus(422);

        $this->assertEquals(0, SupAppraisal::query()->findOrFail($appraisalId)->scores()->count());
    }

    public function test_penilai_tidak_boleh_menilai_dirinya_sendiri(): void
    {
        [, $assessorUser, $teacher] = $this->actors();

        $this->actingAsUser($assessorUser)
            ->postJson('/api/v1/supervisi/appraisals', [
                'supervisee_employee_id' => $teacher->id,
                'appraisal_type' => 'PKG',
                'assessor_employee_id' => $teacher->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assessor_employee_id');

        // Akun kepala sekolah menilai dirinya sendiri (assessor diambil dari akun).
        $kepalaEmployeeId = (int) Employee::query()->where('full_name', 'Hj. Siti Rahmawati')->value('id');

        $this->actingAsUser($assessorUser)
            ->postJson('/api/v1/supervisi/appraisals', [
                'supervisee_employee_id' => $kepalaEmployeeId,
                'appraisal_type' => 'NKKS',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assessor_employee_id');
    }

    public function test_hasil_terbit_terkunci_dan_dapat_dibuka_ulang(): void
    {
        [$assessor, $assessorUser, $teacher] = $this->actors();
        [$appraisalId, $indicators] = $this->createAppraisal($assessorUser, $teacher, $assessor);

        $scores = array_map(fn (array $indicator) => [
            'indicator_id' => $indicator['id'],
            'score' => 3,
        ], $indicators);

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", ['scores' => $scores])
            ->assertOk();

        $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate")
            ->assertOk();

        $published = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/publish")
            ->assertOk();

        $this->assertEquals('PUBLISHED', $published->json('data.appraisal.status'));
        $this->assertNotNull($published->json('data.appraisal.published_at'));

        // Terbit → tidak dapat diubah.
        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", ['scores' => $scores])
            ->assertStatus(422);

        $kalkulasi = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate");

        // Bobot 50 + 20 + 10 + 10 = 90% → ditolak karena normalisasi dimatikan.
        $this->assertSame(422, $kalkulasi->status(), json_encode($kalkulasi->json()));

        // Buka ulang dengan alasan → dapat diperbaiki, alasan tercatat.
        $reopened = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/reopen", [
                'reason' => 'Ada tambahan bukti pelatihan yang belum dinilai.',
            ])
            ->assertOk();

        $this->assertEquals('DRAFT', $reopened->json('data.appraisal.status'));
        $this->assertNull($reopened->json('data.appraisal.published_at'));
        $this->assertCount(1, $reopened->json('data.appraisal.snapshot.reopenings'));

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => [['indicator_id' => $indicators[0]['id'], 'score' => 4]],
            ])
            ->assertOk();
    }

    public function test_tinjauan_pegawai_multi_sumber_bobot_60_20_10_10(): void
    {
        [$assessor, $assessorUser, $teacher] = $this->actors();
        [$appraisalId] = $this->createAppraisal($assessorUser, $teacher, $assessor, 'STAFF_REVIEW');

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/sources", [
                'sources' => [
                    ['source' => 'SUPERVISOR', 'weight' => 60, 'score' => 80],
                    ['source' => 'PEER', 'weight' => 20, 'score' => 70],
                    ['source' => 'SELF', 'weight' => 10, 'score' => 90],
                    ['source' => 'STUDENT', 'weight' => 10, 'score' => 100],
                ],
            ])
            ->assertOk();

        $calculated = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate")
            ->assertOk();

        $this->assertEquals(81.0, $calculated->json('data.appraisal.final_score'));
        $this->assertEquals(100.0, $calculated->json('data.appraisal.snapshot.steps.multi_source.total_weight'));
        $this->assertCount(4, $calculated->json('data.appraisal.snapshot.steps.multi_source.breakdown'));

        // Sekolah mematikan normalisasi otomatis: bobot wajib tepat 100%.
        $this->actingAsUser($assessorUser)
            ->putJson('/api/v1/supervisi/settings', [
                'values' => ['appraisal_normalize_weights' => false],
            ])
            ->assertOk();

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/sources", [
                'sources' => [
                    ['source' => 'SUPERVISOR', 'weight' => 50, 'score' => 80],
                    ['source' => 'PEER', 'weight' => 20, 'score' => 70],
                ],
            ])
            ->assertOk();

        $kalkulasi = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate");

        // Bobot 50 + 20 + 10 + 10 = 90% → ditolak karena normalisasi dimatikan.
        $this->assertSame(422, $kalkulasi->status(), json_encode($kalkulasi->json()));
    }

    public function test_skp_kuadran_dari_rating_maupun_persentase(): void
    {
        [$assessor, $assessorUser, $teacher] = $this->actors();

        // SKP periodik tidak memakai instrumen: rating diisi langsung.
        $appraisalId = (int) $this->actingAsUser($assessorUser)->postJson('/api/v1/supervisi/appraisals', [
            'supervisee_employee_id' => $teacher->id,
            'appraisal_type' => 'SKP_PERIODIK',
            'period_type' => 'TAHUNAN',
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->endOfYear()->toDateString(),
            'assessor_employee_id' => $assessor->id,
        ])->assertCreated()->json('data.appraisal.id');

        $this->assertNull(SupAppraisal::query()->findOrFail($appraisalId)->instrument_version_id);

        // Rating di atas + sesuai ekspektasi → Baik (100% angka kredit).
        $baik = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate", [
                'result_rating' => 'DI_ATAS_EKSPEKTASI',
                'behavior_rating' => 'SESUAI_EKSPEKTASI',
            ])
            ->assertOk();

        $this->assertEquals('BAIK', $baik->json('data.appraisal.final_predicate'));
        $this->assertEquals('Baik', $baik->json('data.appraisal.grade_label'));
        $this->assertEquals(100.0, $baik->json('data.appraisal.credit_percent'));

        // Rating diturunkan dari persentase: 105% (di atas) dan 95% (sesuai).
        $otomatis = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate", [
                'result_percent' => 105,
                'behavior_percent' => 95,
            ])
            ->assertOk();

        $this->assertEquals('DI_ATAS_EKSPEKTASI', $otomatis->json('data.appraisal.result_rating'));
        $this->assertEquals('SESUAI_EKSPEKTASI', $otomatis->json('data.appraisal.behavior_rating'));
        $this->assertEquals('BAIK', $otomatis->json('data.appraisal.final_predicate'));
        $this->assertEquals(100.0, $otomatis->json('data.appraisal.final_score'));

        // Kombinasi terendah → predikat terendah dan memicu PIP.
        $terendah = $this->actingAsUser($assessorUser)
            ->postJson("/api/v1/supervisi/appraisals/{$appraisalId}/calculate", [
                'result_rating' => 'DI_BAWAH_EKSPEKTASI',
                'behavior_rating' => 'DI_BAWAH_EKSPEKTASI',
            ])
            ->assertOk();

        $this->assertEquals('SANGAT_KURANG', $terendah->json('data.appraisal.final_predicate'));
        $this->assertEquals(25.0, $terendah->json('data.appraisal.credit_percent'));
        $this->assertTrue($terendah->json('data.appraisal.snapshot.skp.triggers_pip'));
    }

    public function test_acuan_rumus_dan_daftar_penilaian_untuk_sekolah(): void
    {
        [$assessor, $assessorUser, $teacher] = $this->actors();
        [$appraisalId, $indicators] = $this->createAppraisal($assessorUser, $teacher, $assessor);

        $this->actingAsUser($assessorUser)
            ->putJson("/api/v1/supervisi/appraisals/{$appraisalId}/scores", [
                'scores' => [['indicator_id' => $indicators[0]['id'], 'score' => 4]],
            ])
            ->assertOk();

        $list = $this->actingAsUser($assessorUser)
            ->getJson('/api/v1/supervisi/appraisals?type=PKG')
            ->assertOk();

        $this->assertEquals(1, $list->json('data.summary.total'));
        $this->assertEquals(1, $list->json('data.summary.draft'));
        $this->assertEquals(1, $list->json('data.summary.by_type.PKG'));

        $reference = $this->actingAsUser($assessorUser)
            ->getJson('/api/v1/supervisi/appraisals/reference')
            ->assertOk();

        $this->assertEquals(46, $reference->json('data.working_days'));
        $this->assertEquals(56.0, $reference->json('data.instruments.PKG.divisor'));
        $this->assertEquals(125.0, $reference->json('data.predicates.SANGAT_BAIK.credit_percent'));
        $this->assertEquals('BUTUH_PERBAIKAN', $reference->json('data.quadrant_matrix.DI_BAWAH_EKSPEKTASI.DI_ATAS_EKSPEKTASI'));
    }
}
