<?php

namespace App\Services\Assessment;

use App\Models\Assessment;
use App\Models\AssessmentWeight;
use App\Models\ClassStudent;
use App\Models\GradeScale;
use App\Models\GradingFormula;
use App\Models\PassingGradeRule;
use App\Models\Remedial;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Grading engine (A7).
 *
 * Menjawab: "dari semua penilaian yang sudah dicatat, berapa nilai akhir mapel
 * ini untuk tiap siswa, dan sudah tuntas atau belum?"
 *
 * Rantai perhitungannya disengaja terpisah dan tidak disimpan ke tabel mana pun:
 *
 *     skor mentah → persen (dibagi max_score) → aturan remedial → bobot
 *     → nilai akhir → huruf/predikat → KKTP
 *
 * Karena dihitung ulang setiap kali, mengubah bobot atau aturan remedial tidak
 * meninggalkan nilai lama yang basi. Yang disimpan ke database hanyalah skor
 * mentah, sehingga audit trail-nya tetap utuh.
 */
class GradingService
{
    /** Bucket distribusi nilai akhir; batas bawah inklusif, batas atas eksklusif. */
    public const DISTRIBUTION_BANDS = [
        ['label' => '0-59', 'min' => 0, 'max' => 60],
        ['label' => '60-69', 'min' => 60, 'max' => 70],
        ['label' => '70-79', 'min' => 70, 'max' => 80],
        ['label' => '80-89', 'min' => 80, 'max' => 90],
        ['label' => '90-100', 'min' => 90, 'max' => 100.01],
    ];

    protected ?GradingFormula $defaultFormula = null;

    /**
     * Susun nilai akhir satu mapel di satu kelas pada satu semester.
     *
     * @return array<string, mixed>
     */
    public function build(SchoolClass $class, Subject $subject, Semester $semester): array
    {
        $assessments = Assessment::query()
            ->forContext($class->id, $subject->id, $semester->id)
            ->with(['assessmentType', 'gradingFormula'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $weights = $this->weightsFor($assessments, $class, $semester);
        $scores = $this->scoresByAssessment($assessments);
        $remedials = $this->remedialsByAssessment($assessments);

        $scale = GradeScale::resolveFor($class->education_level_id);
        $rule = PassingGradeRule::resolveFor($subject->id, $class->education_level_id, $semester->academic_year_id);
        $passingScore = $rule?->min_score !== null ? (float) $rule->min_score : null;
        $decimals = $this->formula()->decimals();

        $students = $this->studentsOf($class);

        $rows = $students->map(fn (Student $student) => $this->rowFor(
            $student,
            $assessments,
            $weights,
            $scores,
            $remedials,
            $scale,
            $passingScore,
            $decimals,
        ))->values();

        $finals = $rows->map->final_score->filter(fn ($value) => $value !== null)->values()->all();
        $tuntas = $rows->filter(fn (array $r) => $r['tuntas'] === true)->count();
        $scored = $rows->filter(fn (array $r) => $r['final_score'] !== null)->count();

        $weightTotal = array_sum($weights);

        return [
            'school_class_id' => $class->id,
            'class' => ['id' => $class->id, 'name' => $class->name, 'code' => $class->code],
            'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name],
            'semester' => ['id' => $semester->id, 'name' => $semester->name],
            'academic_year_id' => $semester->academic_year_id,
            'passing_grade' => $passingScore,
            'grade_scale' => $scale === null ? null : [
                'id' => $scale->id,
                'code' => $scale->code,
                'name' => $scale->name,
            ],
            'formula' => $this->formulaAttributes(),
            'weights' => $assessments->map(fn (Assessment $a) => [
                'assessment_id' => $a->id,
                'name' => $a->name,
                'type' => $a->assessmentType?->name,
                'status' => $a->status,
                'max_score' => $a->maxScoreValue(),
                'weight' => $weights[$a->id] ?? 0.0,
                'weight_source' => $a->weight !== null
                    ? 'assessment'
                    : (($weights[$a->id] ?? 0) > 0 ? 'type' : 'none'),
                'scored_count' => $scores->get($a->id)?->count() ?? 0,
            ])->values()->all(),
            'weight_total' => round($weightTotal, 2),
            // Bobot semestinya berjumlah 100%; kalau tidak, hasil akhirnya tetap
            // sah (dibagi bobot yang ada) tetapi guru perlu diberi tahu.
            'weights_complete' => abs($weightTotal - 100) < 0.01,
            'assessment_count' => $assessments->count(),
            'students' => $rows->all(),
            'summary' => [
                'total_students' => $rows->count(),
                'scored' => $scored,
                'unscored' => $rows->count() - $scored,
                'tuntas' => $tuntas,
                'belum_tuntas' => $scored - $tuntas,
                'average' => $finals === [] ? null : round(array_sum($finals) / count($finals), $decimals),
                'median' => $finals === [] ? null : $this->median($finals),
                'highest' => $finals === [] ? null : max($finals),
                'lowest' => $finals === [] ? null : min($finals),
                'pass_rate' => $scored === 0 ? null : round($tuntas / $scored * 100, 1),
                'distribution' => $this->distribution($finals),
            ],
            'needs_attention' => $rows
                ->filter(fn (array $r) => $r['final_score'] !== null && $r['tuntas'] === false)
                ->sortBy('final_score')
                ->values()
                ->all(),
        ];
    }

    /**
     * Ringkasan satu penilaian: berapa yang sudah dinilai dan sebarannya.
     *
     * Dipakai daftar nilai guru untuk mengetahui kelas mana yang belum selesai
     * dinilai dan berapa banyak siswa di bawah KKTP.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(Assessment $assessment): array
    {
        $assessment->loadMissing(['subject', 'schoolClass']);

        $students = $this->studentsOf($assessment->schoolClass);
        $max = $assessment->maxScoreValue();

        $scores = Score::query()
            ->where('assessment_id', $assessment->id)
            ->whereNotNull('score')
            ->get()
            ->keyBy('student_id');

        $percentages = $scores
            ->map(fn (Score $score) => $this->percent((float) $score->score, $max))
            ->values()
            ->all();

        $passing = PassingGradeRule::resolveFor(
            $assessment->subject_id,
            $assessment->schoolClass?->education_level_id,
            $assessment->semester?->academic_year_id ?? $assessment->schoolClass?->academic_year_id,
        );
        $passingScore = $passing?->min_score !== null ? (float) $passing->min_score : null;

        $decimals = $this->formulaFor($assessment)->decimals();

        $below = $passingScore === null ? [] : $scores
            ->filter(fn (Score $score) => $this->percent((float) $score->score, $max) < $passingScore)
            ->map(fn (Score $score) => [
                'student_id' => $score->student_id,
                'nis' => $score->student?->nis,
                'full_name' => $score->student?->full_name,
                'score' => (float) $score->score,
            ])
            ->values()
            ->all();

        return [
            'assessment_id' => $assessment->id,
            'total_students' => $students->count(),
            'graded' => count($percentages),
            'pending' => max(0, $students->count() - count($percentages)),
            'max_score' => $max,
            'passing_grade' => $passingScore,
            'average' => $percentages === [] ? null : round(array_sum($percentages) / count($percentages), $decimals),
            'highest' => $percentages === [] ? null : round(max($percentages), $decimals),
            'lowest' => $percentages === [] ? null : round(min($percentages), $decimals),
            'median' => $percentages === [] ? null : $this->median($percentages),
            'pass_rate' => ($passingScore === null || $percentages === []) ? null
                : round(count(array_filter($percentages, fn ($p) => $p >= $passingScore)) / count($percentages) * 100, 1),
            'distribution' => $this->distribution($percentages),
            'below_passing' => $below,
        ];
    }

    // -----------------------------------------------------------------
    // Bagian-bagian perhitungan
    // -----------------------------------------------------------------

    /**
     * Satu baris hasil perhitungan untuk satu siswa.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @param  array<int, float>  $weights
     * @param  Collection<int, Collection<int, Score>>  $scores
     * @param  Collection<int, Collection<int, Remedial>>  $remedials
     * @return array<string, mixed>
     */
    protected function rowFor(
        Student $student,
        Collection $assessments,
        array $weights,
        Collection $scores,
        Collection $remedials,
        ?GradeScale $scale,
        ?float $passingScore,
        int $decimals,
    ): array {
        $items = [];
        $weightedSum = 0.0;
        $scoredWeight = 0.0;

        foreach ($assessments as $assessment) {
            $score = $scores->get($assessment->id)?->get($student->id);

            if ($score === null) {
                continue;
            }

            $max = $assessment->maxScoreValue();
            $percentage = $this->percent((float) $score->score, $max);

            $remedial = $remedials->get($assessment->id)?->get($student->id);
            $remedialRule = null;

            if ($remedial !== null) {
                $formula = $this->formulaFor($assessment);
                $remedialRule = $formula->remedialRule();
                $percentage = $this->applyRemedial(
                    $percentage,
                    $this->percent((float) $remedial->score_after, $max),
                    $remedialRule,
                    $formula->remedialCap(),
                );
            }

            $weight = $weights[$assessment->id] ?? 0.0;
            $weightedSum += $percentage * $weight;
            $scoredWeight += $weight;

            $items[] = [
                'assessment_id' => $assessment->id,
                'name' => $assessment->name,
                'type' => $assessment->assessmentType?->name,
                'status' => $assessment->status,
                'raw_score' => (float) $score->score,
                'max_score' => $max,
                'percentage' => round($percentage, 2),
                'remedial_rule' => $remedialRule,
                'remedial_score_after' => $remedial?->score_after !== null ? (float) $remedial->score_after : null,
                'weight' => $weight,
            ];
        }

        // Dibagi bobot yang benar-benar dinilai, supaya siswa yang penilaiannya
        // belum lengkap tidak otomatis menjadi nol di bagian yang belum ada.
        $final = $scoredWeight > 0 ? $weightedSum / $scoredWeight : null;
        $final = $final === null ? null : round($final, $decimals);

        foreach ($items as $index => $item) {
            $items[$index]['contribution'] = $scoredWeight > 0
                ? round($item['percentage'] * $item['weight'] / $scoredWeight, max($decimals, 2))
                : 0.0;
        }

        $converted = $final === null ? null : $scale?->convert($final);

        return [
            'student_id' => $student->id,
            'nis' => $student->nis,
            'full_name' => $student->full_name,
            'items' => $items,
            'scored_weight' => round($scoredWeight, 2),
            'final_score' => $final,
            'grade' => $converted['grade'] ?? null,
            'predicate' => $converted['predicate'] ?? null,
            'tuntas' => ($final === null || $passingScore === null) ? null : $final >= $passingScore,
        ];
    }

    /**
     * Skor mentah sebagai persen terhadap skor maksimum penilaiannya.
     *
     * Dikalikan dulu baru dibagi: `23 / 40 * 100` menghasilkan 57.4999… pada
     * floating point, sehingga pembulatan desimal 0 menurunkan 57.5 menjadi 57.
     */
    protected function percent(float $score, float $max): float
    {
        return $max <= 0 ? 0.0 : ($score * 100) / $max;
    }

    /**
     * Gabungkan nilai awal dan nilai remedial sesuai aturan sekolah.
     */
    protected function applyRemedial(float $original, float $remedial, string $rule, ?float $cap): float
    {
        return match ($rule) {
            // Remedial tidak boleh menurunkan nilai, dan hasilnya dibatasi.
            GradingFormula::REMEDIAL_CAP => max($original, min($remedial, $cap ?? $remedial)),
            GradingFormula::REMEDIAL_AVERAGE => ($original + $remedial) / 2,
            default => max($original, $remedial),
        };
    }

    /**
     * Bobot tiap penilaian: bobot khusus assessment mengalahkan bobot jenisnya.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @return array<int, float>
     */
    protected function weightsFor(Collection $assessments, SchoolClass $class, Semester $semester): array
    {
        $typeWeights = AssessmentWeight::query()
            ->where('school_class_id', $class->id)
            ->where('academic_year_id', $semester->academic_year_id)
            ->pluck('weight', 'assessment_type_id')
            ->map(fn ($weight) => (float) $weight);

        return $assessments->mapWithKeys(function (Assessment $assessment) use ($typeWeights) {
            $weight = $assessment->weight !== null
                ? (float) $assessment->weight
                : (float) ($typeWeights[$assessment->assessment_type_id] ?? 0);

            return [$assessment->id => $weight];
        })->all();
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return Collection<int, Collection<int, Score>>
     */
    protected function scoresByAssessment(Collection $assessments): Collection
    {
        if ($assessments->isEmpty()) {
            return collect();
        }

        return Score::query()
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->whereNotNull('score')
            ->with('student')
            ->get()
            ->groupBy('assessment_id')
            ->map(fn (Collection $group) => $group->keyBy('student_id'));
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return Collection<int, Collection<int, Remedial>>
     */
    protected function remedialsByAssessment(Collection $assessments): Collection
    {
        if ($assessments->isEmpty()) {
            return collect();
        }

        return Remedial::query()
            ->completed()
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->groupBy('assessment_id')
            ->map(fn (Collection $group) => $group->keyBy('student_id'));
    }

    /**
     * @return Collection<int, Student>
     */
    protected function studentsOf(?SchoolClass $class): Collection
    {
        if ($class === null) {
            return collect();
        }

        return Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $class->id)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Rata-rata yang tidak tertarik nilai ekstrem: rata-rata dua nilai tengah
     * bila jumlah datanya genap.
     *
     * @param  list<float|int>  $values
     */
    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count - 1, 2);

        return $count % 2 === 1
            ? round((float) $values[$middle], 2)
            : round(((float) $values[$middle] + (float) $values[$middle + 1]) / 2, 2);
    }

    /**
     * Sebaran nilai akhir — berguna untuk menilai apakah soal terlalu mudah
     * atau terlalu sulit.
     *
     * @param  list<float|int>  $values
     * @return list<array{label: string, count: int}>
     */
    protected function distribution(array $values): array
    {
        return array_map(fn (array $band) => [
            'label' => $band['label'],
            'count' => count(array_filter(
                $values,
                fn ($value) => (float) $value >= $band['min'] && (float) $value < $band['max'],
            )),
        ], self::DISTRIBUTION_BANDS);
    }

    public function formulaFor(Assessment $assessment): GradingFormula
    {
        return $assessment->gradingFormula ?? $this->formula();
    }

    /**
     * Formula aktif yang dipakai bila sebuah penilaian tidak menentukan sendiri.
     */
    protected function formula(): GradingFormula
    {
        if ($this->defaultFormula !== null) {
            return $this->defaultFormula;
        }

        $this->defaultFormula = GradingFormula::query()->where('is_active', true)->orderByDesc('version')->first()
            ?? GradingFormula::query()->orderBy('id')->first()
            ?? new GradingFormula();

        return $this->defaultFormula;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formulaAttributes(): array
    {
        $formula = $this->formula();

        return [
            'id' => $formula->id,
            'code' => $formula->code,
            'name' => $formula->name,
            'formula_type' => $formula->formula_type,
            'remedial_rule' => $formula->remedialRule(),
            'remedial_cap' => $formula->remedialCap(),
            'decimals' => $formula->decimals(),
        ];
    }
}
