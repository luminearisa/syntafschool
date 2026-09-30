<?php

namespace App\Modules\Supervisi\Application\Services;

use InvalidArgumentException;

/**
 * Rumus penilaian kinerja yang dipakai lintas layar: PKG, PKG BK, NKKS,
 * tinjauan pegawai berbobot, kuadran SKP, dan penilaian multi-sumber (360°).
 *
 * Semua fungsi murni (tanpa basis data) agar dapat diuji langsung dengan
 * angka contoh dari pedoman resmi. Setiap langkah dikembalikan apa adanya
 * sehingga pemanggil dapat menyimpannya ke `snapshot` sebagai jejak audit —
 * bila rumus berubah di kemudian hari, dokumen lama tetap dapat dihitung ulang
 * dengan angka yang sama.
 */
class AppraisalCalculator
{
    /** Pembagi hari kerja untuk koreksi kehadiran (bawaan Permendikbud 35/2010). */
    public const WORKING_DAYS = 46;

    public const RATING_ABOVE = 'DI_ATAS_EKSPEKTASI';

    public const RATING_MATCH = 'SESUAI_EKSPEKTASI';

    public const RATING_BELOW = 'DI_BAWAH_EKSPEKTASI';

    /**
     * Predikat kinerja ASN (PermenPANRB 6/2022) beserta bobot angka kreditnya.
     *
     * Persentase angka kredit mengikuti kebiasaan penilaian kinerja ASN;
     * sekolah dapat menimpanya lewat pengaturan `skp_predicate_credit`.
     *
     * @var array<string,array{label:string,credit_percent:float,triggers_pip:bool}>
     */
    public const PREDICATES = [
        'SANGAT_BAIK' => ['label' => 'Sangat Baik', 'credit_percent' => 125.0, 'triggers_pip' => false],
        'BAIK' => ['label' => 'Baik', 'credit_percent' => 100.0, 'triggers_pip' => false],
        'BUTUH_PERBAIKAN' => ['label' => 'Butuh Perbaikan', 'credit_percent' => 75.0, 'triggers_pip' => true],
        'KURANG' => ['label' => 'Kurang', 'credit_percent' => 50.0, 'triggers_pip' => true],
        'SANGAT_KURANG' => ['label' => 'Sangat Kurang', 'credit_percent' => 25.0, 'triggers_pip' => true],
    ];

    /**
     * Kuadran kinerja 3×3: baris = rating hasil kerja, kolom = rating perilaku.
     *
     * @var array<string,array<string,string>>
     */
    public const DEFAULT_MATRIX = [
        self::RATING_ABOVE => [
            self::RATING_ABOVE => 'SANGAT_BAIK',
            self::RATING_MATCH => 'BAIK',
            self::RATING_BELOW => 'KURANG',
        ],
        self::RATING_MATCH => [
            self::RATING_ABOVE => 'BAIK',
            self::RATING_MATCH => 'BAIK',
            self::RATING_BELOW => 'KURANG',
        ],
        self::RATING_BELOW => [
            self::RATING_ABOVE => 'BUTUH_PERBAIKAN',
            self::RATING_MATCH => 'BUTUH_PERBAIKAN',
            self::RATING_BELOW => 'SANGAT_KURANG',
        ],
    ];

    /** Ambang pengubahan nilai persen menjadi rating perilaku/hasil kerja. */
    public const DEFAULT_THRESHOLDS = ['above' => 100.0, 'below' => 90.0];

    /**
     * Konversi nilai mentah ke skala 100 (mis. PKG 42 dari 56 → 75).
     */
    public function convertToHundred(?float $raw, ?float $max, int $precision = 2): ?float
    {
        if ($raw === null || $max === null || $max <= 0) {
            return null;
        }

        return round($raw / $max * 100, $precision);
    }

    /**
     * Koreksi kehadiran: NPK = Nilai × (100% − (hari alfa / hari kerja) × 100%).
     *
     * Mengikuti Permendikbud 35/2010: setiap hari tidak masuk tanpa keterangan
     * mengurangi nilai; seluruh langkah dikembalikan untuk diaudit.
     *
     * @return array{percent:float,corrected:float,absence_days:int,working_days:int,reduction:float}
     */
    public function attendanceCorrection(
        float $score,
        int $absenceDays,
        int $workingDays = self::WORKING_DAYS,
        int $precision = 2,
    ): array {
        if ($workingDays <= 0) {
            throw new InvalidArgumentException('Jumlah hari kerja harus lebih besar dari nol.');
        }

        if ($absenceDays < 0) {
            throw new InvalidArgumentException('Hari tidak masuk tanpa keterangan tidak boleh negatif.');
        }

        $reduction = min(100.0, round($absenceDays / $workingDays * 100, $precision));

        return [
            'percent' => round(100 - $reduction, $precision),
            'corrected' => round($score * (100 - $reduction) / 100, $precision),
            'absence_days' => $absenceDays,
            'working_days' => $workingDays,
            'reduction' => $reduction,
        ];
    }

    /**
     * Rating (di atas / sesuai / di bawah ekspektasi) dari nilai persen.
     *
     * @param  array{above?:float,below?:float}  $thresholds
     */
    public function ratingFromScore(float $percent, array $thresholds = []): string
    {
        $above = $thresholds['above'] ?? self::DEFAULT_THRESHOLDS['above'];
        $below = $thresholds['below'] ?? self::DEFAULT_THRESHOLDS['below'];

        return match (true) {
            $percent > $above => self::RATING_ABOVE,
            $percent >= $below => self::RATING_MATCH,
            default => self::RATING_BELOW,
        };
    }

    /** Label & bobot angka kredit sebuah predikat (dapat ditimpa sekolah). */
    public function predicate(string $predicate, array $overrides = []): array
    {
        $base = self::PREDICATES[$predicate] ?? throw new InvalidArgumentException("Predikat {$predicate} tidak dikenal.");
        $credit = $overrides[$predicate] ?? null;

        return [
            'predicate' => $predicate,
            'label' => $base['label'],
            'credit_percent' => $credit === null ? $base['credit_percent'] : (float) $credit,
            'triggers_pip' => $base['triggers_pip'],
        ];
    }

    /**
     * Predikat kinerja dari kuadran hasil kerja × perilaku kerja.
     *
     * @param  array<string,array<string,string>>|null  $matrix
     * @return array{predicate:string,label:string,credit_percent:float,triggers_pip:bool,result_rating:string,behavior_rating:string}
     */
    public function skpPredicate(
        string $resultRating,
        string $behaviorRating,
        ?array $matrix = null,
        array $creditOverrides = [],
    ): array {
        $used = $matrix ?: self::DEFAULT_MATRIX;
        $predicate = $used[$resultRating][$behaviorRating] ?? null;

        if ($predicate === null) {
            throw new InvalidArgumentException(
                "Kombinasi rating {$resultRating} × {$behaviorRating} tidak ada pada matriks kuadran."
            );
        }

        return [
            ...$this->predicate($predicate, $creditOverrides),
            'result_rating' => $resultRating,
            'behavior_rating' => $behaviorRating,
        ];
    }

    /**
     * Rata-rata berbobot penilaian multi-sumber (atasan, rekan, diri, siswa…).
     *
     * Bila `$autoNormalize` dimatikan, jumlah bobot **wajib** 100% — sekolah
     * yang memakai bobot wajib tidak bisa “menutupi” selisih bobot.
     *
     * @param  list<array{source:string,score:float|null,weight:float}>  $sources
     * @return array{score:float|null,total_weight:float,normalization_applied:bool,breakdown:list<array<string,mixed>>}
     */
    public function multiSource(array $sources, bool $autoNormalize = true, float $tolerance = 0.01, int $precision = 2): array
    {
        $scored = array_values(array_filter($sources, fn (array $row) => $row['score'] !== null));
        $totalWeight = round(array_sum(array_map(fn (array $row) => (float) $row['weight'], $scored)), 2);

        if (! $autoNormalize && $scored !== [] && abs($totalWeight - 100.0) > $tolerance) {
            throw new InvalidArgumentException(
                'Bobot sumber penilaian harus berjumlah 100% (saat ini ' . $totalWeight . '%).'
            );
        }

        $weighted = 0.0;
        $breakdown = [];

        foreach ($scored as $row) {
            $weight = (float) $row['weight'];
            $weighted += (float) $row['score'] * $weight;
            $breakdown[] = [
                'source' => $row['source'],
                'score' => round((float) $row['score'], $precision),
                'weight' => $weight,
                'contribution' => $totalWeight > 0 ? round((float) $row['score'] * $weight / $totalWeight, $precision) : null,
            ];
        }

        return [
            'score' => $totalWeight > 0 ? round($weighted / $totalWeight, $precision) : null,
            'total_weight' => $totalWeight,
            'normalization_applied' => abs($totalWeight - 100.0) > $tolerance,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Instrumen bawaan yang dipakai setiap jenis penilaian kinerja.
     *
     * @return array<string,array{code:string,divisor:float,label:string}>
     */
    public function defaultInstruments(): array
    {
        return [
            'PKG' => ['code' => 'pkg-pembelajaran', 'divisor' => 56.0, 'label' => 'PKG Guru (14 kompetensi × 4)'],
            'PKG_BK' => ['code' => 'pkg-bimbingan-konseling', 'divisor' => 68.0, 'label' => 'PKG Guru BK (17 kompetensi × 4)'],
            'NKKS' => ['code' => 'kepala-sekolah-nkks', 'divisor' => 24.0, 'label' => 'NKKS Kepala Sekolah (6 aspek × 4)'],
            'STAFF_REVIEW' => ['code' => 'tinjauan-kinerja-pegawai', 'divisor' => 60.0, 'label' => 'Tinjauan Pegawai (berbobot 1–5)'],
            'PIP' => ['code' => 'pip-perbaikan-kinerja', 'divisor' => 220.0, 'label' => 'Evaluasi PIP'],
        ];
    }
}
