<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\PassingGradeRule;
use App\Models\Remedial;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\Assessment\ScoreRecorder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A7 — Penilaian + skor siswa pada semester aktif.
 *
 * Tujuan seeder ini: gradebook, rapor (A9), dan dashboard punya data nyata.
 * Satu kelas–mapel diisi satu penilaian per jenis yang dibobot, sehingga total
 * bobotnya 100% dan `weights_complete` terbukti benar di UI.
 *
 * Penulisan skor sengaja lewat {@see ScoreRecorder}, bukan `Score::create()`,
 * supaya data contoh ikut berisi huruf/predikat hasil konversi dan jejak revisi
 * yang autentik. Karena itu status assessment dibuat `draft` saat skor ditulis
 * lalu digeser ke status akhirnya — assessment yang terkunci tidak bisa dinilai.
 *
 * Idempoten: dijalankan berulang tidak menggandakan penilaian, skor, maupun
 * remedial; nilai contoh deterministik sehingga tidak memunculkan revisi baru.
 * Catatan: menjalankan ulang seeder ini juga mengembalikan status penilaian ke
 * pola contoh, jadi jangan dipakai pada database yang sudah berisi data nyata.
 */
class AssessmentSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::query()->where('is_active', true)->first()
            ?? Semester::query()->first();

        if (! $semester) {
            $this->command?->warn('AssessmentSeeder dilewati: belum ada semester.');

            return;
        }

        $classSubjects = ClassSubject::query()
            ->with(['subject', 'schoolClass'])
            ->get()
            ->filter(fn (ClassSubject $cs) => $cs->subject !== null && $cs->schoolClass !== null)
            ->values();

        if ($classSubjects->isEmpty()) {
            $this->command?->warn('AssessmentSeeder dilewati: class_subjects kosong.');

            return;
        }

        $typeIds = AssessmentType::query()->pluck('id', 'code');

        if ($typeIds->isEmpty()) {
            $this->command?->warn('AssessmentSeeder dilewati: AssessmentTypeSeeder belum dijalankan.');

            return;
        }

        $recorder = new ScoreRecorder();
        $userId = User::query()->orderBy('id')->value('id');
        [$start, $span] = $this->semesterWindow($semester);

        $assessmentCount = 0;
        $scoreCount = 0;
        $remedialCount = 0;

        foreach ($classSubjects as $classSubject) {
            $students = $this->studentsOf((int) $classSubject->school_class_id);

            if ($students->isEmpty()) {
                continue;
            }

            foreach ($this->templates() as $index => $template) {
                $typeId = $typeIds[$template['type']] ?? null;

                if ($typeId === null) {
                    continue;
                }

                $assessment = $this->makeAssessment($classSubject, $semester, $template, (int) $typeId, $start, $span);

                $assessmentCount++;
                $scoreCount += $this->seedScores($assessment, $students, $template, $index, $recorder, $userId);
                $remedialCount += $this->seedRemedials($assessment, $template, $classSubject, $semester);
            }
        }

        $this->command?->info(
            "Penilaian: {$assessmentCount} assessment, {$scoreCount} skor siswa, {$remedialCount} catatan remedial."
        );
    }

    /**
     * Rentang waktu semester: tanggal mulai dan panjangnya dalam hari.
     *
     * @return array{0: Carbon, 1: int}
     */
    protected function semesterWindow(Semester $semester): array
    {
        $start = $semester->start_date
            ? Carbon::parse($semester->start_date)
            : Carbon::now()->subDays(120);

        $end = $semester->end_date
            ? Carbon::parse($semester->end_date)
            : $start->copy()->addDays(120);

        return [$start, max(30, abs((int) $start->diffInDays($end)))];
    }

    /**
     * @return Collection<int, Student>
     */
    protected function studentsOf(int $classId): Collection
    {
        return Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $classId)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $template
     */
    protected function makeAssessment(
        ClassSubject $classSubject,
        Semester $semester,
        array $template,
        int $typeId,
        Carbon $start,
        int $span,
    ): Assessment {
        $date = $start->copy()->addDays((int) round($span * $template['progress']));

        return Assessment::updateOrCreate(
            [
                'school_class_id' => $classSubject->school_class_id,
                'subject_id' => $classSubject->subject_id,
                'semester_id' => $semester->id,
                'name' => $template['name'],
            ],
            [
                'employee_id' => $classSubject->employee_id,
                'assessment_type_id' => $typeId,
                'description' => $template['description'],
                'max_score' => $template['max_score'],
                // weight dibiarkan kosong: bobot mengikuti jenis penilaian per kelas.
                'weight' => null,
                'date' => $date->toDateString(),
                'status' => Assessment::STATUS_DRAFT,
                'grading_formula_id' => null,
            ],
        );
    }

    /**
     * Tulis skor mentah lewat recorder. Sebagian jenis sengaja belum dinilai
     * penuh supaya guru melihat pekerjaan yang benar-benar berjalan.
     *
     * @param  Collection<int, Student>  $students
     * @param  array<string, mixed>  $template
     */
    protected function seedScores(
        Assessment $assessment,
        Collection $students,
        array $template,
        int $index,
        ScoreRecorder $recorder,
        ?int $userId,
    ): int {
        $max = $assessment->maxScoreValue();
        $take = (int) floor($students->count() * $template['coverage']);
        $written = 0;

        foreach ($students->take($take) as $position => $student) {
            $percentage = $this->percentageFor($position, $index, $template);

            $recorder->put(
                $assessment,
                (int) $student->id,
                round($percentage * $max / 100, 2),
                $percentage < 60 ? 'Perlu pendampingan tambahan.' : null,
                $userId,
            );

            $written++;
        }

        // Status akhir dipasang setelah skor ditulis.
        $assessment->update(['status' => $template['status']]);

        return $written;
    }

    /**
     * Persentase nilai seorang siswa pada satu penilaian.
     *
     * Deterministik tapi tidak seragam: tiap siswa punya "kemampuan" dasar yang
     * tersebar 55–94, lalu digeser sedikit per jenis penilaian. Tanpa variasi
     * ini rata-rata semua siswa jatuh di angka yang sama dan sebaran nilainya
     * terlihat palsu.
     *
     * @param  array<string, mixed>  $template
     */
    protected function percentageFor(int $position, int $index, array $template): int
    {
        $ability = 55 + (($position * 13) % 40);
        $shift = (($index * 7) % 11) - 5;
        $bias = $template['bias'] ?? 0;

        return max(30, min(100, $ability + $shift + $bias));
    }

    /**
     * Remedial untuk siswa di bawah KKTP. Hanya pada tugas harian: itulah pola
     * paling umum di sekolah (remedial per tugas, bukan per sumatif besar).
     *
     * @param  array<string, mixed>  $template
     */
    protected function seedRemedials(
        Assessment $assessment,
        array $template,
        ClassSubject $classSubject,
        Semester $semester,
    ): int {
        if ($template['type'] !== 'tugas') {
            return 0;
        }

        $rule = PassingGradeRule::resolveFor(
            $classSubject->subject_id,
            $classSubject->schoolClass?->education_level_id,
            $semester->academic_year_id,
        );

        if ($rule === null) {
            return 0;
        }

        $max = $assessment->maxScoreValue();
        $passing = (float) $rule->min_score;
        $target = round(min($max, $max * $passing / 100), 2);

        $below = $assessment->scores()
            ->whereNotNull('score')
            ->where('score', '<', $target)
            ->orderBy('score')
            ->limit(3)
            ->get();

        $keep = [];

        foreach ($below as $position => $score) {
            // Baris ketiga sengaja tanpa nilai setelah: remedial yang masih terjadwal.
            $done = $position < 2;

            $remedial = Remedial::updateOrCreate(
                [
                    'assessment_id' => $assessment->id,
                    'student_id' => $score->student_id,
                ],
                [
                    'score_before' => $score->score,
                    'score_after' => $done ? $target : null,
                    'date' => $assessment->date?->copy()
                        ->addDays($done ? 7 : 14)
                        ->toDateString(),
                    'notes' => $done
                        ? "Remedial selesai, nilai mengikuti KKTP {$passing}."
                        : 'Rencana remedial, belum dikerjakan.',
                ],
            );

            $keep[] = $remedial->id;
        }

        // Baris remedial sisa run sebelumnya (misalnya siswa yang nilainya sudah
        // berubah) ikut dibersihkan agar jumlah baris tidak menumpuk.
        Remedial::query()
            ->where('assessment_id', $assessment->id)
            ->whereNotIn('id', $keep)
            ->delete();

        return count($keep);
    }

    /**
     * Satu penilaian per jenis yang dibobot, disebar sepanjang semester.
     *
     * `bias` menggeser persentase contoh per jenis: praktik cenderung lebih
     * tinggi daripada sumatif. `coverage` adalah bagian siswa yang sudah dinilai.
     *
     * @return list<array<string, mixed>>
     */
    protected function templates(): array
    {
        return [
            [
                'type' => 'tugas',
                'name' => 'Tugas Harian',
                'description' => 'Kumpulan tugas tertulis pada bab berjalan.',
                'bias' => 0,
                'max_score' => 100,
                'progress' => 0.10,
                'coverage' => 1.0,
                'status' => Assessment::STATUS_FINALIZED,
            ],
            [
                'type' => 'kuis',
                'name' => 'Kuis Singkat',
                'description' => 'Kuis 15 menit di awal pembelajaran.',
                'bias' => 3,
                'max_score' => 40,
                'progress' => 0.22,
                'coverage' => 1.0,
                'status' => Assessment::STATUS_FINALIZED,
            ],
            [
                'type' => 'praktik',
                'name' => 'Penilaian Praktik',
                'description' => 'Unjuk kerja per kelompok di laboratorium.',
                'bias' => 8,
                'max_score' => 100,
                'progress' => 0.32,
                'coverage' => 0.9,
                'status' => Assessment::STATUS_FINALIZED,
            ],
            [
                'type' => 'sts',
                'name' => 'Sumatif Tengah Semester',
                'description' => 'Penilaian sumatif paruh pertama semester.',
                'bias' => -6,
                'max_score' => 100,
                'progress' => 0.45,
                'coverage' => 1.0,
                'status' => Assessment::STATUS_VERIFIED,
            ],
            [
                'type' => 'projek',
                'name' => 'Projek Penguatan',
                'description' => 'Projek kelompok lintas kompetensi.',
                'bias' => 5,
                'max_score' => 100,
                'progress' => 0.60,
                'coverage' => 0.7,
                'status' => Assessment::STATUS_SUBMITTED,
            ],
            [
                'type' => 'portofolio',
                'name' => 'Portofolio Semester',
                'description' => 'Kumpulan karya terbaik selama satu semester.',
                'bias' => 2,
                'max_score' => 100,
                'progress' => 0.75,
                'coverage' => 0.4,
                'status' => Assessment::STATUS_DRAFT,
            ],
            [
                'type' => 'sas',
                'name' => 'Sumatif Akhir Semester',
                'description' => 'Penilaian sumatif akhir semester, belum dilaksanakan.',
                'bias' => -5,
                'max_score' => 100,
                'progress' => 0.92,
                'coverage' => 0,
                'status' => Assessment::STATUS_DRAFT,
            ],
        ];
    }
}
