<?php

namespace Tests\Feature\Supervisi;

use App\Modules\Supervisi\Application\Services\InstrumentBuilderService;
use App\Modules\Supervisi\Application\Services\ScoringEngine;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupVisit;

/**
 * Mesin penskoran: skala, angka (dua arah), ya/tidak, NA, dan bobot.
 */
class SupScoringEngineTest extends SupTestCase
{
    /** Instrumen campuran: skala 1–4, angka 0–100, angka 0–10 (makin kecil makin baik), ya/tidak. */
    protected function mixedVersion(string $scoringMode = 'PERCENT'): SupInstrumentVersion
    {
        $builder = app(InstrumentBuilderService::class);

        $instrument = SupInstrument::query()->create([
            'code' => 'uji-campuran',
            'name' => 'Uji Campuran',
            'category' => 'AKADEMIK',
            'target_type' => 'GURU',
        ]);

        $version = $builder->createVersion($instrument, ['scoring_mode' => $scoringMode]);

        $builder->syncStructure($version, [[
            'title' => 'Bagian',
            'weight' => 1,
            'indicators' => [
                [
                    'statement' => 'Skala rubrik',
                    'guidance' => 'Pilih 1–4.',
                    'input_type' => 'SCALE',
                    'options' => [
                        ['label' => 'Kurang', 'score' => 1],
                        ['label' => 'Cukup', 'score' => 2],
                        ['label' => 'Baik', 'score' => 3],
                        ['label' => 'Amat Baik', 'score' => 4],
                    ],
                ],
                [
                    'statement' => 'Angka persentase',
                    'guidance' => 'Isi 0–100.',
                    'input_type' => 'NUMBER',
                    'score_min' => 0,
                    'score_max' => 100,
                    'direction' => 'HIGHER_BETTER',
                    'allow_na' => true,
                ],
                [
                    'statement' => 'Jumlah keterlambatan',
                    'guidance' => 'Makin sedikit makin baik.',
                    'input_type' => 'NUMBER',
                    'score_min' => 0,
                    'score_max' => 10,
                    'direction' => 'LOWER_BETTER',
                ],
                [
                    'statement' => 'Daftar periksa',
                    'guidance' => 'Ya bila terpenuhi.',
                    'input_type' => 'BOOLEAN',
                    'score_yes' => 1,
                    'score_no' => 0,
                ],
            ],
        ]], null);

        return $version->fresh(['sections.indicators.options']);
    }

    public function test_nilai_menggabungkan_skala_angka_dua_arah_dan_ya_tidak(): void
    {
        $version = $this->mixedVersion();
        $visit = $this->visit($version);

        // Skala 3, angka 80, keterlambatan 2 (efektif 8 dari 10), ya = 1
        $this->answer($visit, $version, 0, ['score' => 3]);
        $this->answer($visit, $version, 1, ['value_number' => 80, 'score' => 80]);
        $this->answer($visit, $version, 2, ['value_number' => 2, 'score' => 2]);
        $this->answer($visit, $version, 3, ['value_text' => 'YES', 'score' => 1]);

        $result = app(ScoringEngine::class)->scoreVisit($visit->fresh(['answers']), $version);

        // raw = 3 + 80 + 8 + 1 = 92 ; max = 4 + 100 + 10 + 1 = 115
        $this->assertSame(92.0, $result['raw']);
        $this->assertSame(115.0, $result['max']);
        $this->assertSame(80.0, $result['final']);
        $this->assertSame([], $result['missing_required']);
    }

    public function test_indikator_na_dikeluarkan_dari_pembilang_dan_penyebut(): void
    {
        $version = $this->mixedVersion();
        $visit = $this->visit($version);

        $this->answer($visit, $version, 0, ['score' => 3]);
        $this->answer($visit, $version, 1, ['is_na' => true]);
        $this->answer($visit, $version, 2, ['value_number' => 2]);
        $this->answer($visit, $version, 3, ['value_text' => 'YES']);

        $result = app(ScoringEngine::class)->scoreVisit($visit->fresh(['answers']), $version);

        // raw = 3 + 8 + 1 = 12 ; max = 4 + 10 + 1 = 15
        $this->assertSame(12.0, $result['raw']);
        $this->assertSame(15.0, $result['max']);
        $this->assertSame(80.0, $result['final']);
        $this->assertSame(1, $result['excluded']);
    }

    public function test_indikator_wajib_yang_kosong_dilaporkan_sebagai_belum_lengkap(): void
    {
        $version = $this->mixedVersion();
        $visit = $this->visit($version);

        $this->answer($visit, $version, 0, ['score' => 4]);

        $result = app(ScoringEngine::class)->scoreVisit($visit->fresh(['answers']), $version);

        $this->assertCount(3, $result['missing_required']);
    }

    public function test_skor_di_luar_rentang_dipotong_ke_batas_yang_sah(): void
    {
        $version = $this->mixedVersion();
        $visit = $this->visit($version);

        $this->answer($visit, $version, 0, ['score' => 4]);
        $this->answer($visit, $version, 1, ['value_number' => 150]);
        $this->answer($visit, $version, 2, ['value_number' => -3]);
        $this->answer($visit, $version, 3, ['value_text' => 'YES']);

        $result = app(ScoringEngine::class)->scoreVisit($visit->fresh(['answers']), $version);

        // 4 + 100 + 10 + 1 = 115 dari maksimum 115
        $this->assertSame(115.0, $result['raw']);
        $this->assertSame(100.0, $result['final']);
    }

    public function test_mode_berbobot_memakai_bobot_bagian(): void
    {
        $builder = app(InstrumentBuilderService::class);
        $instrument = SupInstrument::query()->create([
            'code' => 'uji-bobot',
            'name' => 'Uji Bobot',
            'category' => 'KINERJA_PEGAWAI',
            'target_type' => 'UMUM',
        ]);

        $version = $builder->createVersion($instrument, ['scoring_mode' => 'WEIGHTED']);

        $builder->syncStructure($version, [
            [
                'title' => 'Hasil Kerja',
                'weight' => 3,
                'indicators' => [[
                    'statement' => 'Capaian target',
                    'guidance' => 'Skala 1–5.',
                    'input_type' => 'SCALE',
                    'options' => [
                        ['label' => '1', 'score' => 1],
                        ['label' => '5', 'score' => 5],
                    ],
                ]],
            ],
            [
                'title' => 'Perilaku Kerja',
                'weight' => 1,
                'indicators' => [[
                    'statement' => 'Nilai dasar',
                    'guidance' => 'Skala 1–5.',
                    'input_type' => 'SCALE',
                    'options' => [
                        ['label' => '1', 'score' => 1],
                        ['label' => '5', 'score' => 5],
                    ],
                ]],
            ],
        ], null);

        $version = $version->fresh(['sections.indicators.options']);
        $visit = $this->visit($version);

        $this->answer($visit, $version, 0, ['score' => 5]); // bagian 1 → 100%
        $this->answer($visit, $version, 1, ['score' => 1]); // bagian 2 → 20%

        $result = app(ScoringEngine::class)->scoreVisit($visit->fresh(['answers']), $version);

        // (100×3 + 20×1) / 4 = 80
        $this->assertSame(80.0, $result['final']);
    }

    public function test_band_predikat_diambil_dari_skala_versi(): void
    {
        $version = $this->mixedVersion();
        $engine = app(ScoringEngine::class);

        $this->assertSame('Baik', $engine->bandForVersion($version, 85.0)['label']);
        $this->assertSame('Amat Baik', $engine->bandForVersion($version, 95.0)['label']);
        $this->assertSame('Cukup', $engine->bandForVersion($version, 75.0)['label']);
        $this->assertSame('Kurang', $engine->bandForVersion($version, 40.0)['label']);
    }

    // ------------------------------------------------------------------

    protected function visit(SupInstrumentVersion $version): SupVisit
    {
        $supervisor = $this->employee();
        $supervisee = $this->employee();

        return SupVisit::query()->create([
            'code' => 'SUP/TES/'.fake()->unique()->numerify('######'),
            'supervisor_employee_id' => $supervisor->id,
            'supervisee_employee_id' => $supervisee->id,
            'activity_kind' => 'CLASS_OBSERVATION',
            'instrument_version_id' => $version->id,
            'status' => 'OBSERVED',
        ]);
    }

    /** @param array<string,mixed> $payload */
    protected function answer(SupVisit $visit, SupInstrumentVersion $version, int $indicatorIndex, array $payload): void
    {
        $indicator = $version->sections->flatMap->indicators->values()->get($indicatorIndex);

        $visit->answers()->create([
            'indicator_id' => $indicator->id,
            'answered_at' => now(),
            ...$payload,
        ]);
    }
}
