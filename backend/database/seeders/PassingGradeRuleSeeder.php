<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\PassingGradeRule;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * A7 — KKTP (Kriteria Ketercapaian Tujuan Pembelajaran), pengganti KKM.
 *
 * Aturan dibuat bertingkat untuk membuktikan mekanisme resolusi:
 * - satu aturan umum sekolah (semua dimensi kosong) sebagai jaring pengaman;
 * - satu aturan per mapel wajib yang terikat pada tahun ajaran aktif, sehingga
 *   mapel dengan target lebih tinggi (Matematika, Bahasa) tidak diukur dengan
 *   batas yang sama dengan mapel lainnya.
 *
 * `PassingGradeRule::resolveFor()` memilih aturan paling spesifik lebih dulu,
 * jadi mapel yang belum punya aturan sendiri otomatis memakai batas umum.
 */
class PassingGradeRuleSeeder extends Seeder
{
    /** KKTP default sekolah untuk semua mapel yang belum punya aturan sendiri. */
    protected const GENERAL_SCORE = 75;

    /**
     * KKTP per kode mata pelajaran wajib, berlaku pada tahun ajaran aktif.
     *
     * @var array<string, float>
     */
    protected array $bySubject = [
        'PABP' => 75,
        'PKN' => 75,
        'BIND' => 80,
        'MTK' => 80,
        'BING' => 78,
        'SEJIND' => 75,
        'PJOK' => 75,
        'SENBUD' => 75,
        'PKWU' => 75,
        'INF' => 78,
    ];

    public function run(): void
    {
        $year = AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->first();

        PassingGradeRule::updateOrCreate(
            [
                'subject_id' => null,
                'education_level_id' => null,
                'academic_year_id' => null,
            ],
            ['min_score' => self::GENERAL_SCORE],
        );

        $subjectIds = Subject::query()->pluck('id', 'code');
        $created = 0;

        foreach ($this->bySubject as $code => $minScore) {
            $subjectId = $subjectIds[$code] ?? null;

            if ($subjectId === null) {
                continue;
            }

            PassingGradeRule::updateOrCreate(
                [
                    'subject_id' => $subjectId,
                    'education_level_id' => null,
                    'academic_year_id' => $year?->id,
                ],
                ['min_score' => $minScore],
            );

            $created++;
        }

        $this->command?->info(
            "KKTP: {$created} aturan per mapel + 1 aturan umum (".self::GENERAL_SCORE.'). '
            .($year ? "Terikat tahun ajaran {$year->name}." : 'Tanpa batasan tahun ajaran.')
        );
    }
}
