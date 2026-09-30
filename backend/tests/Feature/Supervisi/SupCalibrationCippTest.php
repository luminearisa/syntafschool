<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Database\Seeders\SupInstrumentSeeder;
use App\Modules\Supervisi\Models\SupCalibrationSession;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupProgramEvaluation;
use App\Modules\Supervisi\Models\SupVisit;

/**
 * Fase 4 — kalibrasi penilai (moderasi) dan evaluasi program dengan CIPP.
 */
class SupCalibrationCippTest extends SupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupInstrumentSeeder::class);
    }

    /** @return array{0: Employee, 1: User, 2: Employee, 3: User, 4: Employee} */
    protected function actors(): array
    {
        $head = $this->employee(['full_name' => 'Hj. Siti Rahmawati', 'position' => 'Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $head, 'kind' => 'KEPALA_SEKOLAH', 'can_finalize' => true]);
        $headUser = $this->employeeWithAccount($head, 'kepala-sekolah');

        $second = $this->employee(['full_name' => 'Pak Wakasek', 'position' => 'Wakil Kepala Sekolah']);
        $this->registerSupervisor(['employee' => $second, 'kind' => 'WAKASEK', 'can_finalize' => true]);
        $secondUser = $this->employeeWithAccount($second, 'kepala-unit');

        $teacher = $this->employee(['full_name' => 'Ahmad Fauzi', 'position' => 'Guru Biologi']);
        $teacherUser = $this->employeeWithAccount($teacher, 'guru');

        return [$head, $headUser, $second, $secondUser, $teacher];
    }

    protected function indicatorIds(int $limit = 3): array
    {
        $version = $this->publishedInstrument()['version']->load('sections.indicators');

        return $version->indicators->take($limit)->pluck('id')->all();
    }

    public function test_sesi_kalibrasi_menghitung_selisih_dan_menuntut_kesepakatan(): void
    {
        [$head, $headUser, $second, $secondUser] = $this->actors();
        $indicators = $this->indicatorIds(3);

        $sessionId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/calibrations', [
            'title' => 'Moderasi nilai observasi kelas semester ganjil',
            'threshold' => 1.0,
            'held_at' => now()->subDay()->toIso8601String(),
        ])->assertCreated()->json('data.session.id');

        // Dua penilai pada tiga indikator: satu di antaranya berseilisih jauh.
        $scores = [];

        foreach ($indicators as $index => $indicatorId) {
            $scores[] = ['supervisor_employee_id' => $head->id, 'indicator_id' => $indicatorId, 'score' => 4, 'comment' => 'Sesuai bukti.'];
            $scores[] = [
                'supervisor_employee_id' => $second->id,
                'indicator_id' => $indicatorId,
                // Indikator pertama berseilisih 3 poin → melewati ambang 1,0.
                'score' => $index === 0 ? 1 : 3.5,
                'comment' => 'Perlu bukti tambahan.',
            ];
        }

        $ringkasan = $this->actingAsUser($headUser)
            ->putJson("/api/v1/supervisi/calibrations/{$sessionId}/scores", ['scores' => $scores])
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $ringkasan['statistics']['indicators']);
        $this->assertSame(2, $ringkasan['statistics']['raters']);
        $this->assertEquals(1, $ringkasan['statistics']['exceeding']);
        $this->assertEquals(3.0, $ringkasan['statistics']['max_spread']);

        $pertama = collect($ringkasan['indicators'])->firstWhere('exceeds_threshold', true);
        $this->assertEquals(3.0, $pertama['spread']);
        $this->assertEquals(2.5, $pertama['average']);

        // Selama ada selisih melewati ambang, finalisasi menuntut kesepakatan tertulis.
        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/calibrations/{$sessionId}/finalize", [
            'conclusion' => 'Singkat',
        ])->assertStatus(422);

        $final = $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/calibrations/{$sessionId}/finalize", [
            'conclusion' => 'Disepakati indikator 1 dinilai setelah bukti tambahan dikumpulkan; penilai kedua menaikkan skor menjadi 3.',
        ])->assertOk()->json('data');

        $this->assertSame('FINALIZED', $final['session']['status']);
        $this->assertNotNull($final['session']['finalized_at']);
        $this->assertFalse($final['abilities']['finalize']);
        $this->assertSame($head->id, SupCalibrationSession::query()->findOrFail($sessionId)->finalized_by);

        // Sesi terkunci setelah difinalkan.
        $this->actingAsUser($headUser)->putJson("/api/v1/supervisi/calibrations/{$sessionId}/scores", [
            'scores' => [['supervisor_employee_id' => $head->id, 'indicator_id' => $indicators[0], 'score' => 4]],
        ])->assertStatus(422);

        // Wakasek (kepala-unit) boleh mengelola sesi; guru tidak.
        $this->actingAsUser($secondUser)->getJson("/api/v1/supervisi/calibrations/{$sessionId}")->assertOk();

        $teacherUser = $this->employeeWithAccount($this->employee(['full_name' => 'Guru Tanpa Izin']), 'guru');

        $this->actingAsUser($teacherUser)->postJson('/api/v1/supervisi/calibrations', [
            'title' => 'Sesi karangan guru',
        ])->assertStatus(403);
    }

    public function test_kesenjangan_penilaian_dari_kegiatan_nyata_terdeteksi(): void
    {
        [$head, $headUser, , $secondUser, $teacher] = $this->actors();

        // Supervisor A menilai tinggi, supervisor B menilai rendah pada
        // instrumen yang sama: selisihnya harus muncul sebagai bahan moderasi.
        $versionId = $this->publishedInstrument()['version']->id;

        $this->finalizedVisitWithOptions($headUser, $teacher, [3, 3, 3, 3], $versionId);
        $this->finalizedVisitWithOptions($secondUser, $teacher, [0, 0, 0, 0], $versionId);

        $rows = $this->actingAsUser($headUser)
            ->getJson('/api/v1/supervisi/calibrations/divergence?limit=5')
            ->assertOk()
            ->json('data.rows');

        $this->assertNotEmpty($rows);
        $this->assertGreaterThan(0, $rows[0]['spread']);
        $this->assertNotEmpty($rows[0]['supervisors']);
        $this->assertCount(2, $rows[0]['supervisors']);
        $this->assertGreaterThan(0, $rows[0]['school_average']);

        // Ringkasan analitik juga menandai supervisor yang menyimpang.
        $analitik = $this->actingAsUser($headUser)->getJson('/api/v1/supervisi/analytics')->assertOk()->json('data');
        $this->assertCount(2, $analitik['supervisors']);
        $this->assertContains(true, collect($analitik['supervisors'])->pluck('needs_calibration')->all());
        $this->assertEqualsCanonicalizing(
            ['Hj. Siti Rahmawati', 'Pak Wakasek'],
            collect($analitik['supervisors'])->pluck('full_name')->all(),
        );
        $this->assertSame($head->id, $analitik['supervisors'][0]['supervisor_employee_id']);
    }

    public function test_evaluasi_cipp_program_dengan_bukti_dan_rekomendasi(): void
    {
        [$head, $headUser, $second, $secondUser, $teacher] = $this->actors();

        $this->finalizedVisitWithOptions($headUser, $teacher, [3, 3, 2, 3]);

        $programId = (int) $this->actingAsUser($headUser)->postJson('/api/v1/supervisi/programs', [
            'title' => 'Program Supervisi Akademik 2026',
            'scope' => 'AKADEMIK',
            'objective' => 'Memastikan seluruh guru disupervisi minimal satu kali per semester.',
            'starts_at' => now()->startOfYear()->toDateString(),
            'ends_at' => now()->endOfYear()->toDateString(),
        ])->assertCreated()->json('data.id');


        $evaluasiId = (int) $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/programs/{$programId}/evaluations", [
                'period' => '2026/2027 - Semester Ganjil',
                'context' => ['kebutuhan' => 'Rapor pendidikan menunjukkan mutu pembelajaran perlu ditingkatkan'],
                'input' => ['supervisor' => 3, 'instrumen' => 2],
                'process' => ['cakupan' => 'sebagian guru'],
                'product' => ['perubahan' => 'belum terukur'],
                'context_score' => 85,
                'input_score' => 75,
                'process_score' => 65,
                'product_score' => 55,
                'stakeholders' => ['kepala_sekolah', 'guru', 'pengawas'],
                'finding' => 'Pelaksanaan belum menjangkau seluruh guru; umpan balik pasca-observasi belum tercatat.',
            ])->assertCreated()->json('data.evaluation.id');

        $detail = $this->actingAsUser($headUser)
            ->getJson("/api/v1/supervisi/evaluations/{$evaluasiId}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(70.0, $detail['evaluation']['average']);
        $this->assertSame('2026/2027 - Semester Ganjil', $detail['evaluation']['period']);
        $this->assertCount(3, $detail['evaluation']['stakeholders']);

        // Bukti otomatis dari data supervisi ikut disajikan sebagai pembanding.
        $this->assertEquals(1, $detail['evidence']['visits']['finalized']);
        $this->assertArrayHasKey('coverage', $detail['evidence']);
        $this->assertArrayHasKey('guide', $detail);

        // Rekomendasi wajib sebelum finalisasi.
        $this->actingAsUser($headUser)->postJson("/api/v1/supervisi/evaluations/{$evaluasiId}/finalize")->assertStatus(422);

        $this->actingAsUser($headUser)->patchJson("/api/v1/supervisi/evaluations/{$evaluasiId}", [
            'product_score' => 60,
            'recommendation' => 'Tambah jadwal observasi dan wajibkan unggah umpan balik setelah setiap kunjungan.',
        ])->assertOk();

        $final = $this->actingAsUser($headUser)
            ->postJson("/api/v1/supervisi/evaluations/{$evaluasiId}/finalize")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($final['evaluation']['finalized_at']);
        $this->assertFalse($final['abilities']['update']);

        // Evaluasi terkunci; administrasi lain (wakasek) hanya melihat.
        $this->actingAsUser($headUser)->patchJson("/api/v1/supervisi/evaluations/{$evaluasiId}", [
            'finding' => 'Diubah setelah final',
        ])->assertStatus(422);

        $daftar = $this->actingAsUser($secondUser)->getJson('/api/v1/supervisi/evaluations')->assertOk()->json('data');
        $this->assertEquals(1, $daftar['summary']['evaluations']);
        $this->assertEquals(1, $daftar['summary']['finalized']);
        $this->assertEquals(4, count($daftar['dimensions']));

        $this->assertNotNull($second->id);
        $this->assertSame(60.0, SupProgramEvaluation::query()->findOrFail($evaluasiId)->product_score);
    }

    /**
     * Kegiatan difinalkan dengan pilihan opsi tertentu per indikator.
     *
     * @param  list<int>  $optionIndexes
     */
    protected function finalizedVisitWithOptions(User $assessor, Employee $supervisee, array $optionIndexes, ?int $versionId = null): SupVisit
    {
        // Dua supervisor harus memakai versi instrumen yang sama agar
        // kesenjangan penilaiannya sebanding (indikator yang sama).
        $version = $versionId === null
            ? $this->publishedInstrument()['version']->load('sections.indicators.options')
            : \App\Modules\Supervisi\Models\SupInstrumentVersion::query()->with('sections.indicators.options')->findOrFail($versionId);

        $visitId = (int) $this->actingAsUser($assessor)->postJson('/api/v1/supervisi/visits', [
            'supervisee_employee_id' => $supervisee->id,
            'instrument_version_id' => $version->id,
        ])->assertCreated()->json('data.visit.id');

        $answers = [];
        $index = 0;

        foreach ($version->indicators as $indicator) {
            $options = $indicator->options->sortBy('score')->values();
            $choice = $optionIndexes[$index % count($optionIndexes)];
            $index++;

            $answers[] = match ($indicator->input_type->value) {
                'SCALE' => [
                    'indicator_id' => $indicator->id,
                    'option_id' => $options[min($choice, $options->count() - 1)]->id,
                    'comment' => 'Bukti observasi kelas.',
                ],
                'NUMBER' => ['indicator_id' => $indicator->id, 'value_number' => 5 + $choice, 'comment' => 'Bukti dokumen.'],
                'BOOLEAN' => [
                    'indicator_id' => $indicator->id,
                    'value_text' => $choice <= 0 ? 'NO' : 'YES',
                    'comment' => 'Diperiksa saat kunjungan.',
                ],
                default => ['indicator_id' => $indicator->id, 'value_long_text' => 'Catatan observasi.'],
            };
        }

        $this->actingAsUser($assessor)
            ->putJson("/api/v1/supervisi/visits/{$visitId}/answers", ['answers' => $answers])
            ->assertOk();

        // Nilai rendah menuntut temuan + rekomendasi sebelum finalisasi.
        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/findings", ['findings' => [[
            'component' => 'PERENCANAAN',
            'priority' => 'HIGH',
            'weakness' => 'Perangkat pembelajaran belum lengkap',
            'cause' => 'Beban mengajar padat',
            'recommendation' => 'Pendampingan penyusunan perangkat',
        ]]])->assertOk();

        $this->actingAsUser($assessor)->putJson("/api/v1/supervisi/visits/{$visitId}/conferences/POST", [
            'held_at' => now()->toIso8601String(),
            'agreements' => ['Rencana perbaikan disepakati'],
            'reflection' => 'Guru menerima umpan balik.',
        ])->assertOk();

        $this->actingAsUser($assessor)->postJson("/api/v1/supervisi/visits/{$visitId}/finalize")->assertOk();

        return SupVisit::query()->findOrFail($visitId);
    }
}
