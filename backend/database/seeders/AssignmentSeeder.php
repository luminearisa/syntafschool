<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A11b — contoh tugas & pengumpulan pada semester aktif.
 *
 * Tugas dibuat dari `class_subjects` yang sudah ada sehingga kelas, mapel, dan
 * gurunya konsisten dengan struktur pengajaran nyata. Idempoten: dijalankan
 * berulang kali tidak menggandakan tugas maupun pengumpulan.
 */
class AssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('is_active', true)->first() ?? Semester::first();

        if (! $semester) {
            $this->command?->warn('Semester belum tersedia — AssignmentSeeder dilewati.');

            return;
        }

        $classSubjects = ClassSubject::query()
            ->with(['subject', 'employee'])
            ->get()
            ->filter(fn (ClassSubject $cs) => $cs->subject !== null)
            ->values();

        if ($classSubjects->isEmpty()) {
            $this->command?->warn('class_subjects kosong — AssignmentSeeder dilewati.');

            return;
        }

        $fallbackTeacherId = Employee::where('is_teacher', true)->orderBy('id')->value('id');

        if (! $fallbackTeacherId) {
            $this->command?->warn('Belum ada guru (is_teacher) — AssignmentSeeder dilewati.');

            return;
        }

        $createdBy = User::query()->orderBy('id')->value('id');
        $base = $semester->start_date ? Carbon::parse($semester->start_date) : Carbon::now();

        $templates = $this->templates();

        $createdAssignments = 0;
        $createdSubmissions = 0;

        // Sebanyak 12 tugas pertama dipetakan ke 12 baris class_subjects.
        foreach ($classSubjects->take(12) as $index => $classSubject) {
            $template = $templates[$index % count($templates)];

            $assignedDate = $base->copy()->addDays($template['assigned_offset']);
            $dueDate = $assignedDate->copy()->addDays(7);
            $published = $template['status'] !== Assignment::STATUS_DRAFT;

            $assignment = Assignment::updateOrCreate(
                [
                    'class_id' => $classSubject->school_class_id,
                    'subject_id' => $classSubject->subject_id,
                    'semester_id' => $semester->id,
                    'title' => $template['title'],
                ],
                [
                    'teacher_id' => $classSubject->employee_id ?? $fallbackTeacherId,
                    'description' => $template['description'],
                    'instructions' => $template['instructions'],
                    'type' => $template['type'],
                    'max_score' => $template['max_score'],
                    'weight' => $template['weight'],
                    'assigned_date' => $assignedDate->toDateString(),
                    'due_date' => $dueDate->toDateString(),
                    'allow_late_submission' => $template['allow_late'],
                    'status' => $template['status'],
                    'published_at' => $published ? $assignedDate->copy()->setTime(7, 0) : null,
                    'created_by' => $createdBy,
                ],
            );

            $createdAssignments++;

            if (! $published) {
                continue;
            }

            $createdSubmissions += $this->seedSubmissions($assignment, $dueDate, $template['max_score']);
        }

        $this->command?->info(
            "Tugas: {$createdAssignments} baris diproses, {$createdSubmissions} pengumpulan dibuat."
        );
    }

    /**
     * Buat pengumpulan untuk sebagian siswa: sebagian sudah dinilai, sebagian belum.
     */
    protected function seedSubmissions(Assignment $assignment, Carbon $dueDate, float $maxScore): int
    {
        $studentIds = ClassStudent::where('school_class_id', $assignment->class_id)
            ->orderBy('student_id')
            ->pluck('student_id');

        if ($studentIds->isEmpty()) {
            return 0;
        }

        // Sekitar 60% siswa mengumpulkan; sisanya dibiarkan belum mengumpulkan.
        $take = max(1, (int) ceil($studentIds->count() * 0.6));
        $submitting = $studentIds->take($take);

        $created = 0;

        foreach ($submitting as $position => $studentId) {
            $existing = AssignmentSubmission::where('assignment_id', $assignment->id)
                ->where('student_id', $studentId)
                ->first();

            if ($existing) {
                continue;
            }

            $submittedAt = $dueDate->copy()->subDay()->setTime(19, 30);

            // Setiap siswa ketiga sudah dinilai.
            $graded = $position % 3 === 0;

            AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $studentId,
                'submitted_at' => $submittedAt,
                'content' => 'Pengumpulan '.$assignment->title.' oleh siswa #'.$studentId.'.',
                'score' => $graded ? round($maxScore * 0.85, 2) : null,
                'feedback' => $graded ? 'Pekerjaan baik, pertahankan.' : null,
                'graded_by' => $graded ? $assignment->created_by : null,
                'graded_at' => $graded ? $submittedAt->copy()->addDays(2) : null,
                'status' => $graded
                    ? AssignmentSubmission::STATUS_GRADED
                    : AssignmentSubmission::STATUS_SUBMITTED,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function templates(): array
    {
        return [
            [
                'title' => 'Latihan Soal Bab 1',
                'description' => 'Mengerjakan latihan soal pada buku paket bab 1.',
                'instructions' => 'Tulis tangan pada buku tugas, kumpulkan dalam format PDF bila daring.',
                'type' => Assignment::TYPE_TUGAS,
                'max_score' => 100,
                'weight' => 10,
                'assigned_offset' => 3,
                'allow_late' => false,
                'status' => Assignment::STATUS_PUBLISHED,
            ],
            [
                'title' => 'Kuis Bab 2',
                'description' => 'Kuis singkat untuk mengukur pemahaman bab 2.',
                'instructions' => 'Kerjakan mandiri, tanpa membuka catatan.',
                'type' => Assignment::TYPE_KUIS,
                'max_score' => 50,
                'weight' => 15,
                'assigned_offset' => 10,
                'allow_late' => true,
                'status' => Assignment::STATUS_PUBLISHED,
            ],
            [
                'title' => 'Projek Kelompok Bab 3',
                'description' => 'Projek kelompok membuat laporan sederhana pada bab 3.',
                'instructions' => 'Kelompok 4 orang, sertakan daftar pustaka.',
                'type' => Assignment::TYPE_PROJEK,
                'max_score' => 100,
                'weight' => 20,
                'assigned_offset' => 20,
                'allow_late' => true,
                'status' => Assignment::STATUS_PUBLISHED,
            ],
            [
                'title' => 'Praktikum Bab 4',
                'description' => 'Laporan hasil praktikum bab 4.',
                'instructions' => 'Lampirkan tabel pengamatan dan analisis.',
                'type' => Assignment::TYPE_PRAKTIK,
                'max_score' => 100,
                'weight' => 25,
                'assigned_offset' => 30,
                'allow_late' => false,
                'status' => Assignment::STATUS_CLOSED,
            ],
            [
                'title' => 'Portofolio Semester',
                'description' => 'Kumpulan karya terbaik selama satu semester.',
                'instructions' => 'Minimal lima karya, beri refleksi singkat tiap karya.',
                'type' => Assignment::TYPE_PORTOFOLIO,
                'max_score' => 100,
                'weight' => 30,
                'assigned_offset' => 40,
                'allow_late' => true,
                'status' => Assignment::STATUS_DRAFT,
            ],
        ];
    }
}
