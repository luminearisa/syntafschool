<?php

namespace Tests\Unit\Supervisi;

use App\Modules\Supervisi\Application\Services\AppraisalCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rumus penilaian kinerja diuji langsung dengan angka contoh dari pedoman
 * (PKG 56/68/24, koreksi kehadiran Permendikbud 35/2010, kuadran
 * PermenPANRB 6/2022, dan bobot multi-sumber 60/20/10/10).
 */
class AppraisalCalculatorTest extends TestCase
{
    protected AppraisalCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AppraisalCalculator;
    }

    public function test_konversi_pkg_guru_mapel_dari_56(): void
    {
        // 14 kompetensi × skor 4 = 56 → nilai 100.
        $this->assertSame(100.0, $this->calculator->convertToHundred(56.0, 56.0));

        // 42 dari 56 → 75 (contoh pada pedoman).
        $this->assertSame(75.0, $this->calculator->convertToHundred(42.0, 56.0));

        // 50 dari 56 → 89,29.
        $this->assertSame(89.29, $this->calculator->convertToHundred(50.0, 56.0));

        // Tanpa nilai → null, bukan 0 (agar dokumen kosong tidak dianggap gagal).
        $this->assertNull($this->calculator->convertToHundred(null, 56.0));
        $this->assertNull($this->calculator->convertToHundred(10.0, 0.0));
    }

    public function test_konversi_pkg_guru_bk_dari_68_dan_nkks_dari_24(): void
    {
        $this->assertSame(100.0, $this->calculator->convertToHundred(68.0, 68.0));
        $this->assertSame(75.0, $this->calculator->convertToHundred(51.0, 68.0));
        $this->assertSame(100.0, $this->calculator->convertToHundred(24.0, 24.0));
        $this->assertSame(75.0, $this->calculator->convertToHundred(18.0, 24.0));
    }

    public function test_koreksi_kehadiran_sesuai_permendikbud_35_2010(): void
    {
        // Tanpa alfa → nilai tidak berubah.
        $bersih = $this->calculator->attendanceCorrection(89.29, 0, 46);
        $this->assertSame(100.0, $bersih['percent']);
        $this->assertSame(89.29, $bersih['corrected']);

        // 5 hari tanpa keterangan dari 46 hari kerja → potongan 10,87%.
        $lima = $this->calculator->attendanceCorrection(89.29, 5, 46);
        $this->assertSame(10.87, $lima['reduction']);
        $this->assertSame(89.13, $lima['percent']);
        $this->assertSame(79.58, $lima['corrected']);

        // 46 hari (seluruh hari kerja) → nilai terkoreksi nol, tidak negatif.
        $penuh = $this->calculator->attendanceCorrection(90.0, 46, 46);
        $this->assertSame(0.0, $penuh['percent']);
        $this->assertSame(0.0, $penuh['corrected']);
    }

    public function test_koreksi_kehadiran_menolak_masukan_tidak_masuk_akal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->attendanceCorrection(80.0, 3, 0);
    }

    public function test_rating_hasil_kerja_dari_persentase(): void
    {
        $this->assertSame(AppraisalCalculator::RATING_ABOVE, $this->calculator->ratingFromScore(101.0));
        $this->assertSame(AppraisalCalculator::RATING_MATCH, $this->calculator->ratingFromScore(100.0));
        $this->assertSame(AppraisalCalculator::RATING_MATCH, $this->calculator->ratingFromScore(90.0));
        $this->assertSame(AppraisalCalculator::RATING_BELOW, $this->calculator->ratingFromScore(89.99));

        // Ambang dapat diubah sekolah.
        $this->assertSame(
            AppraisalCalculator::RATING_ABOVE,
            $this->calculator->ratingFromScore(96.0, ['above' => 95, 'below' => 85]),
        );
    }

    public function test_kuadran_skp_matriks_bawaan(): void
    {
        $above = AppraisalCalculator::RATING_ABOVE;
        $match = AppraisalCalculator::RATING_MATCH;
        $below = AppraisalCalculator::RATING_BELOW;

        $sangat = $this->calculator->skpPredicate($above, $above);
        $this->assertSame('SANGAT_BAIK', $sangat['predicate']);
        $this->assertSame('Sangat Baik', $sangat['label']);
        $this->assertSame(125.0, $sangat['credit_percent']);
        $this->assertFalse($sangat['triggers_pip']);

        $this->assertSame('BAIK', $this->calculator->skpPredicate($above, $match)['predicate']);
        $this->assertSame('BAIK', $this->calculator->skpPredicate($match, $above)['predicate']);
        $this->assertSame('BAIK', $this->calculator->skpPredicate($match, $match)['predicate']);
        $this->assertSame('KURANG', $this->calculator->skpPredicate($above, $below)['predicate']);
        $this->assertSame('KURANG', $this->calculator->skpPredicate($match, $below)['predicate']);
        $this->assertSame('BUTUH_PERBAIKAN', $this->calculator->skpPredicate($below, $match)['predicate']);
        $this->assertSame('BUTUH_PERBAIKAN', $this->calculator->skpPredicate($below, $above)['predicate']);

        $terendah = $this->calculator->skpPredicate($below, $below);
        $this->assertSame('SANGAT_KURANG', $terendah['predicate']);
        $this->assertSame(25.0, $terendah['credit_percent']);
        $this->assertTrue($terendah['triggers_pip'], 'Predikat terendah harus memicu PIP.');
    }

    public function test_kuadran_skp_matriks_sekolah_dan_kombinasi_tak_dikenal(): void
    {
        $matrix = [
            AppraisalCalculator::RATING_ABOVE => [AppraisalCalculator::RATING_ABOVE => 'BAIK'],
        ];

        $custom = $this->calculator->skpPredicate(
            AppraisalCalculator::RATING_ABOVE,
            AppraisalCalculator::RATING_ABOVE,
            $matrix,
        );

        $this->assertSame('BAIK', $custom['predicate']);

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->skpPredicate(AppraisalCalculator::RATING_MATCH, AppraisalCalculator::RATING_MATCH, $matrix);
    }

    public function test_penilaian_multi_sumber_bobot_60_20_10_10(): void
    {
        $hasil = $this->calculator->multiSource([
            ['source' => 'SUPERVISOR', 'score' => 80.0, 'weight' => 60],
            ['source' => 'PEER', 'score' => 70.0, 'weight' => 20],
            ['source' => 'SELF', 'score' => 90.0, 'weight' => 10],
            ['source' => 'STUDENT', 'score' => 100.0, 'weight' => 10],
        ]);

        // (80×60 + 70×20 + 90×10 + 100×10) / 100 = 81
        $this->assertSame(81.0, $hasil['score']);
        $this->assertSame(100.0, $hasil['total_weight']);
        $this->assertFalse($hasil['normalization_applied']);
        $this->assertCount(4, $hasil['breakdown']);
        $this->assertSame(48.0, $hasil['breakdown'][0]['contribution']);

        // Sumber tanpa skor diabaikan, bukan dianggap 0.
        $tanpaSiswa = $this->calculator->multiSource([
            ['source' => 'SUPERVISOR', 'score' => 80.0, 'weight' => 60],
            ['source' => 'STUDENT', 'score' => null, 'weight' => 10],
        ]);

        $this->assertSame(80.0, $tanpaSiswa['score']);
        $this->assertSame(60.0, $tanpaSiswa['total_weight']);
    }

    public function test_multi_sumber_wajib_100_persen_kecuali_dinormalisasi(): void
    {
        $sumber = [
            ['source' => 'SUPERVISOR', 'score' => 90.0, 'weight' => 60],
            ['source' => 'PEER', 'score' => 70.0, 'weight' => 30],
        ];

        // Normalisasi otomatis: dihitung terhadap total bobot yang ada.
        $auto = $this->calculator->multiSource($sumber, true);
        $this->assertSame(83.33, $auto['score']);
        $this->assertTrue($auto['normalization_applied']);

        // Tanpa normalisasi: bobot 90% ditolak.
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->multiSource($sumber, false);
    }

    public function test_daftar_instrumen_bawaan_setiap_jenis_penilaian(): void
    {
        $instrumen = $this->calculator->defaultInstruments();

        $this->assertSame('pkg-pembelajaran', $instrumen['PKG']['code']);
        $this->assertSame(56.0, $instrumen['PKG']['divisor']);
        $this->assertSame(68.0, $instrumen['PKG_BK']['divisor']);
        $this->assertSame(24.0, $instrumen['NKKS']['divisor']);
        $this->assertSame('tinjauan-kinerja-pegawai', $instrumen['STAFF_REVIEW']['code']);
    }
}
