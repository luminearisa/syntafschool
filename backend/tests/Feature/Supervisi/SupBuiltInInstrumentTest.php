<?php

namespace Tests\Feature\Supervisi;

use App\Modules\Supervisi\Database\Seeders\SupAcademicInstrumentSeeder;
use App\Modules\Supervisi\Database\Seeders\SupStaffAppraisalInstrumentSeeder;
use App\Modules\Supervisi\Models\SupInstrument;
use App\Modules\Supervisi\Models\SupInstrumentVersion;

/**
 * Instrumen bawaan harus menghasilkan butir & skor maksimum yang benar.
 *
 * Angka-angka di tes ini adalah angka baku pedoman (perangkat 12 butir/48,
 * telaah RPP 36 butir/72, pengamatan 40 butir/40, PKG 14/56, PKG BK 17/68,
 * NKKS 24) sehingga kesalahan penulisan butir cepat terdeteksi.
 */
class SupBuiltInInstrumentTest extends SupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupAcademicInstrumentSeeder::class);
        $this->seed(SupStaffAppraisalInstrumentSeeder::class);
    }

    public function test_butir_dan_skor_maksimum_instrumen_bawaan_sesuai_pedoman(): void
    {
        $harapan = [
            'perangkat-pembelajaran' => ['butir' => 12, 'maks' => 48.0],
            'telaah-rpp' => ['butir' => 36, 'maks' => 72.0],
            'pengamatan-pembelajaran' => ['butir' => 40, 'maks' => 40.0],
            'penilaian-hasil-belajar' => ['butir' => 11, 'maks' => 44.0],
            'dampak-supervisi' => ['butir' => 5, 'maks' => 20.0],
            'supervisi-manajerial-sekolah' => ['butir' => 8, 'maks' => 32.0],
            'pkg-pembelajaran' => ['butir' => 14, 'maks' => 56.0],
            'pkg-bimbingan-konseling' => ['butir' => 17, 'maks' => 68.0],
            'kepala-sekolah-nkks' => ['butir' => 6, 'maks' => 24.0],
        ];

        foreach ($harapan as $code => $expected) {
            $instrument = SupInstrument::query()->where('code', $code)->first();
            $this->assertNotNull($instrument, "Instrumen {$code} tidak ditemukan.");

            $version = $instrument->versions()->where('status', 'PUBLISHED')->firstOrFail();
            $version->loadMissing('sections.indicators');

            $indikator = $version->sections->flatMap->indicators;

            $this->assertCount($expected['butir'], $indikator, "Jumlah butir {$code} tidak sesuai.");
            $this->assertSame($expected['maks'], (float) $version->max_score, "Skor maksimum {$code} tidak sesuai.");
        }
    }

    public function test_instrumen_bawaan_terbit_dengan_versi_dan_skala_predikat(): void
    {
        $this->assertGreaterThanOrEqual(20, SupInstrument::query()->count());
        $this->assertSame(
            SupInstrument::query()->count(),
            SupInstrumentVersion::query()->where('status', 'PUBLISHED')->count(),
        );

        $pkg = SupInstrument::query()->where('code', 'pkg-pembelajaran')->firstOrFail();
        $version = $pkg->versions()->where('status', 'PUBLISHED')->firstOrFail();

        $this->assertSame('pkg_sebutan', $version->gradeScale->code);

        // Sebutan PKG: 95 → Amat Baik (angka kredit 125%).
        $band = app(\App\Modules\Supervisi\Application\Services\ScoringEngine::class)
            ->bandForVersion($version, 95.0);

        $this->assertSame('Amat Baik', $band['label']);
        $this->assertSame(125, (int) $band['credit_percent']);
    }

    public function test_instrumen_naratif_wawancara_tidak_menghasilkan_nilai(): void
    {
        foreach (['wawancara-pra-observasi', 'wawancara-pasca-observasi'] as $code) {
            $version = SupInstrument::query()->where('code', $code)->firstOrFail()
                ->versions()->where('status', 'PUBLISHED')->firstOrFail();

            $this->assertSame('NARRATIVE', $version->scoring_mode->value);
            $this->assertFalse($version->scoring_mode->isScored());
        }
    }

    public function test_instrumen_memuat_indikator_berskala_dan_bertipe_angka(): void
    {
        $walkthrough = SupInstrument::query()->where('code', 'walkthrough-kelas')->firstOrFail()
            ->versions()->where('status', 'PUBLISHED')->firstOrFail();
        $walkthrough->loadMissing('sections.indicators');

        $tipe = $walkthrough->sections->flatMap->indicators->pluck('input_type.value')->unique();

        $this->assertTrue($tipe->contains('SCALE'), 'Walkthrough harus punya indikator skala.');
        $this->assertTrue($tipe->contains('NUMBER'), 'Walkthrough harus punya indikator angka.');

        $pengamatan = SupInstrument::query()->where('code', 'pengamatan-pembelajaran')->firstOrFail()
            ->versions()->where('status', 'PUBLISHED')->firstOrFail();
        $pengamatan->loadMissing('sections.indicators');

        $this->assertTrue(
            $pengamatan->sections->flatMap->indicators->every(fn ($indicator) => $indicator->input_type->value === 'BOOLEAN'),
            'Pengamatan pembelajaran memakai daftar periksa ya/tidak.',
        );
    }
}
