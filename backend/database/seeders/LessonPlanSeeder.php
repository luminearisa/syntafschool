<?php

namespace Database\Seeders;

use App\Models\ClassSubject;
use App\Models\LessonPlan;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A11a — contoh RPP / modul ajar pada semester aktif.
 *
 * RPP dibuat dari `class_subjects` yang sudah ada sehingga guru, mapel, dan
 * kelasnya konsisten dengan struktur pengajaran nyata. Statusnya sengaja
 * bervariasi (draft/submitted/approved/rejected) agar alur approval bisa
 * dilihat langsung di UI. Idempoten: dijalankan berulang tidak menggandakan.
 */
class LessonPlanSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('is_active', true)->first() ?? Semester::first();

        if (! $semester) {
            $this->command?->warn('Semester belum tersedia — LessonPlanSeeder dilewati.');

            return;
        }

        $classSubjects = ClassSubject::query()
            ->with(['subject', 'employee', 'schoolClass'])
            ->whereNotNull('employee_id')
            ->get()
            ->filter(fn (ClassSubject $cs) => $cs->subject !== null
                && $cs->employee !== null
                && $cs->employee->is_teacher)
            ->values();

        if ($classSubjects->isEmpty()) {
            $this->command?->warn('class_subjects kosong / belum ada guru pengampu — LessonPlanSeeder dilewati.');

            return;
        }

        $createdBy = User::query()->orderBy('id')->value('id');
        $templates = $this->templates();
        $processed = 0;

        foreach ($classSubjects->take(8) as $index => $classSubject) {
            $template = $templates[$index % count($templates)];

            $code = sprintf(
                'RPP-%s-%s-%d',
                $classSubject->subject->code,
                $classSubject->schoolClass?->code ?? 'ALL',
                $semester->id,
            );

            $approved = $template['status'] === LessonPlan::STATUS_APPROVED;
            $rejected = $template['status'] === LessonPlan::STATUS_REJECTED;

            LessonPlan::updateOrCreate(
                [
                    'teacher_id' => $classSubject->employee_id,
                    'subject_id' => $classSubject->subject_id,
                    'semester_id' => $semester->id,
                    'code' => $code,
                ],
                [
                    'class_id' => $classSubject->school_class_id,
                    'education_level_id' => $classSubject->schoolClass?->education_level_id,
                    'title' => $template['title'],
                    'learning_objectives' => $template['objectives'],
                    'activities' => $template['activities'],
                    'assessment_plan' => $template['assessment'],
                    'media' => $template['media'],
                    'references' => $template['references'],
                    'duration_jp' => $template['duration_jp'],
                    'status' => $template['status'],
                    'approved_by' => $approved ? $createdBy : null,
                    'approved_at' => $approved ? Carbon::now() : null,
                    'rejection_reason' => $rejected
                        ? 'Tujuan pembelajaran belum terukur. Perbaiki indikator capaian lalu ajukan ulang.'
                        : null,
                    'created_by' => $createdBy,
                ],
            );

            $processed++;
        }

        $this->command?->info("RPP: {$processed} baris diproses.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function templates(): array
    {
        return [
            [
                'title' => 'Modul Ajar: Konsep Dasar dan Penerapannya',
                'objectives' => [
                    'Menjelaskan konsep dasar bab 1 dengan bahasa sendiri.',
                    'Menerapkan konsep pada soal kontekstual sederhana.',
                ],
                'activities' => [
                    'Pembuka: apersepsi 10 menit dan pertanyaan pemantik.',
                    'Inti: diskusi kelompok mengerjakan LKPD, presentasi hasil.',
                    'Penutup: refleksi dan penugasan mandiri.',
                ],
                'assessment' => 'Penilaian proses lewat observasi diskusi + kuis singkat di akhir pertemuan.',
                'media' => 'Papan tulis, LKPD, proyektor.',
                'references' => 'Buku teks utama bab 1 dan sumber daring terpercaya.',
                'duration_jp' => 2,
                'status' => LessonPlan::STATUS_APPROVED,
            ],
            [
                'title' => 'Modul Ajar: Analisis dan Pemecahan Masalah',
                'objectives' => [
                    'Menganalisis masalah bertingkat dan memilih strategi penyelesaian.',
                    'Menyajikan hasil analisis secara runtut.',
                ],
                'activities' => [
                    'Pembuka: mengulas materi sebelumnya.',
                    'Inti: latihan terbimbing lalu latihan mandiri bertingkat.',
                    'Penutup: pembahasan soal tersulit bersama.',
                ],
                'assessment' => 'Tes tertulis (kuis) dan penilaian produk hasil kerja kelompok.',
                'media' => 'Slide presentasi dan lembar latihan.',
                'references' => 'Buku paket bab 2 dan bank soal.',
                'duration_jp' => 2,
                'status' => LessonPlan::STATUS_SUBMITTED,
            ],
            [
                'title' => 'Modul Ajar: Praktik Terbimbing',
                'objectives' => [
                    'Melakukan langkah kerja praktik sesuai prosedur keselamatan.',
                    'Mencatat data hasil praktik dengan rapi.',
                ],
                'activities' => [
                    'Pembuka: penjelasan prosedur dan keselamatan kerja.',
                    'Inti: praktik berkelompok dan pendampingan guru.',
                    'Penutup: penyusunan laporan sementara.',
                ],
                'assessment' => 'Penilaian kinerja (rubrik) dan laporan praktik.',
                'media' => 'Alat dan bahan praktikum, lembar kerja.',
                'references' => 'Modul praktikum dan video prosedur.',
                'duration_jp' => 3,
                'status' => LessonPlan::STATUS_DRAFT,
            ],
            [
                'title' => 'Modul Ajar: Pengayaan dan Refleksi',
                'objectives' => [
                    'Mengaitkan materi dengan situasi nyata di sekitar siswa.',
                    'Merefleksikan proses belajar dan menetapkan target berikutnya.',
                ],
                'activities' => [
                    'Pembuka: kuis cepat sebagai pemetaan pemahaman.',
                    'Inti: projek mini kontekstual.',
                    'Penutup: refleksi tertulis dan umpan balik.',
                ],
                'assessment' => 'Penilaian projek mini dengan rubrik dan jurnal refleksi siswa.',
                'media' => 'Lembar projek dan rubrik penilaian.',
                'references' => 'Artikel populer dan buku pengayaan.',
                'duration_jp' => 2,
                'status' => LessonPlan::STATUS_REJECTED,
            ],
        ];
    }
}
