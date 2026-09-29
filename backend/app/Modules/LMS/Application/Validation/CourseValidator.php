<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\CourseCompletionRule;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Models\LmsCourse;

/**
 * Validasi tingkat course (§17).
 *
 * Hanya memeriksa hal-hal yang membuat course **tidak dapat dipakai**: judul,
 * pengampu, isi, aturan penyelesaian, rentang tanggal, prasyarat melingkar,
 * dan sertifikat yang menunjuk aktivitas/kompetensi tidak valid.
 */
class CourseValidator
{
    public function __construct(private readonly PrerequisiteGraphService $graph) {}

    public function validate(LmsCourse $course): ValidationReport
    {
        $report = new ValidationReport;

        $this->validateIdentity($course, $report);
        $this->validateTeaching($course, $report);
        $this->validateSchedule($course, $report);
        $this->validateStructure($course, $report);
        $this->validateCompletion($course, $report);
        $this->validatePrerequisites($course, $report);
        $this->validateCertificate($course, $report);

        return $report;
    }

    private function validateIdentity(LmsCourse $course, ValidationReport $report): void
    {
        if (! filled($course->name)) {
            $report->error('course.title_missing', 'Nama course wajib diisi.', 'course', (int) $course->id, 'Isi nama course pada tab Informasi.');
        } elseif (mb_strlen((string) $course->name) < 3) {
            $report->warning('course.title_too_short', 'Nama course sangat pendek; siswa sulit mengenalinya.', 'course', (int) $course->id, 'Gunakan nama minimal 3 karakter.');
        }

        if (! filled($course->short_description ?? $course->description)) {
            $report->error(
                'course.description_missing',
                'Deskripsi course belum diisi.',
                'course',
                (int) $course->id,
                'Tuliskan 1–2 kalimat tentang isi course.',
            );
        }

        if ($course->visibility === CourseVisibility::PUBLIC && ! filled($course->slug)) {
            $report->error('course.public_without_slug', 'Course publik harus punya slug agar dapat dibuka lewat tautan.', 'course', (int) $course->id);
        }
    }

    private function validateTeaching(LmsCourse $course, ValidationReport $report): void
    {
        $teachers = $course->teachers()->count();

        if ($teachers === 0 && $course->teacher_id === null) {
            $report->error('course.teacher_missing', 'Belum ada guru pengampu; siswa tidak punya penanggung jawab penilaian.', 'course', (int) $course->id, 'Tetapkan minimal satu guru pengampu.');
        }
    }

    private function validateSchedule(LmsCourse $course, ValidationReport $report): void
    {
        if ($course->start_date !== null && $course->end_date !== null && $course->end_date->lt($course->start_date)) {
            $report->error('course.schedule_inverted', 'Tanggal selesai lebih awal daripada tanggal mulai.', 'course', (int) $course->id);
        }

        if ($course->end_date !== null && $course->end_date->isPast() && $course->status?->value === 'PUBLISHED') {
            $report->warning('course.schedule_past', 'Tanggal selesai sudah lewat; periksa apakah course perlu diarsipkan.', 'course', (int) $course->id);
        }
    }

    private function validateStructure(LmsCourse $course, ValidationReport $report): void
    {
        $sections = $course->sections()->count();

        if ($sections === 0) {
            $report->error('course.section_empty', 'Course belum memiliki bagian (section).', 'course', (int) $course->id, 'Tambahkan minimal satu bagian berisi materi.');
        }

        $items = $course->items()->count();

        if ($items === 0) {
            $report->error('course.item_empty', 'Course belum memiliki materi atau aktivitas.', 'course', (int) $course->id);
        }

        $orphans = $course->items()
            ->whereDoesntHave('section')
            ->count();

        if ($orphans > 0) {
            $report->error(
                'course.item_orphan',
                $orphans.' item tidak berada pada bagian mana pun sehingga tidak dapat diakses siswa.',
                'course',
                (int) $course->id,
                'Pindahkan item tersebut ke salah satu bagian.',
                ['orphans' => $orphans],
            );
        }

        $hiddenRequired = $course->items()
            ->where('is_required', true)
            ->where('visibility', ContentVisibility::HIDDEN->value)
            ->count();

        if ($hiddenRequired > 0) {
            $report->error(
                'course.required_item_hidden',
                $hiddenRequired.' item wajib disembunyikan; ketuntasan course tidak akan pernah tercapai.',
                'course',
                (int) $course->id,
                'Tampilkan item wajib atau ubah menjadi opsional.',
                ['items' => $hiddenRequired],
            );
        }

        $emptySections = $course->sections()->whereDoesntHave('items')->pluck('title')->all();

        foreach ($emptySections as $title) {
            $report->warning('course.section_without_item', 'Bagian "'.$title.'" belum berisi materi.', 'course', (int) $course->id);
        }
    }

    private function validateCompletion(LmsCourse $course, ValidationReport $report): void
    {
        $rule = $course->completion_rule instanceof CourseCompletionRule
            ? $course->completion_rule
            : CourseCompletionRule::tryFrom((string) $course->completion_rule);

        if ($rule === null) {
            $report->error(
                'course.completion_rule_invalid',
                'Aturan penyelesaian course "'.(string) $course->completion_rule.'" tidak dikenali.',
                'completion',
                (int) $course->id,
                'Pilih salah satu aturan yang tersedia: '.implode(', ', CourseCompletionRule::values()).'.',
            );

            return;
        }

        if ($rule === CourseCompletionRule::MINIMUM_PROGRESS) {
            $minimum = $course->completion_min_progress;

            if ($minimum === null) {
                $report->error('course.completion_min_progress_missing', 'Aturan MINIMUM_PROGRESS memerlukan ambang persentase.', 'completion', (int) $course->id);
            } elseif ((float) $minimum <= 0 || (float) $minimum > 100) {
                $report->error('course.completion_min_progress_range', 'Ambang persentase harus di antara 1 dan 100.', 'completion', (int) $course->id);
            }
        }

        if ($rule === CourseCompletionRule::MINIMUM_GRADE) {
            if ((float) ($course->completion_min_grade ?? 0) <= 0) {
                $report->error('course.completion_min_grade_missing', 'Aturan MINIMUM_GRADE memerlukan ambang nilai.', 'completion', (int) $course->id);
            }

            if ($course->gradebook()->first() === null) {
                $report->error('course.completion_without_gradebook', 'Aturan penyelesaian berbasis nilai, tetapi gradebook belum dibuat.', 'completion', (int) $course->id);
            }
        }

        if ($rule === CourseCompletionRule::REQUIRED_FINAL_ASSESSMENT) {
            if ($course->required_final_activity_id === null) {
                $report->error('course.final_assessment_missing', 'Aturan penyelesaian menuntut asesmen akhir, tetapi belum ada aktivitas yang dipilih.', 'completion', (int) $course->id);
            } elseif (! $course->items()->where('activity_id', $course->required_final_activity_id)->exists()) {
                $report->error('course.final_assessment_orphan', 'Asesmen akhir yang dipilih tidak lagi terpasang pada course ini.', 'completion', (int) $course->id);
            }
        }

        if ($rule === CourseCompletionRule::ALL_REQUIRED_ACTIVITIES) {
            $activities = $course->items()->where('item_type', ItemType::ACTIVITY->value)->count();

            if ($activities === 0) {
                $report->warning('course.completion_without_activity', 'Course menuntut penyelesaian aktivitas, tetapi belum memiliki aktivitas.', 'completion', (int) $course->id);
            }
        }
    }

    private function validatePrerequisites(LmsCourse $course, ValidationReport $report): void
    {
        foreach ($this->graph->itemCycles($course) as $cycle) {
            $report->error(
                'course.prerequisite_cycle',
                'Prasyarat materi saling melingkar: '.implode(' → ', $cycle['labels']).'.',
                'prerequisite',
                (int) $course->id,
                'Hapus salah satu prasyarat pada rantai tersebut.',
                ['cycle' => $cycle['path']],
            );
        }

        foreach ($course->prerequisites as $prerequisite) {
            if ((int) $prerequisite->prerequisite_course_id === (int) $course->id) {
                $report->error('course.prerequisite_self', 'Course tidak boleh menjadi prasyarat dirinya sendiri.', 'prerequisite', (int) $course->id);
            }

            $target = $prerequisite->prerequisite;

            if ($target === null) {
                $report->error('course.prerequisite_missing', 'Prasyarat menunjuk course yang sudah dihapus.', 'prerequisite', (int) $course->id);
            } elseif ((bool) $prerequisite->is_enforced && ! $target->isPublished()) {
                $report->error(
                    'course.prerequisite_unpublished',
                    'Prasyarat "'.$target->name.'" belum diterbitkan sehingga tidak dapat diselesaikan siswa.',
                    'prerequisite',
                    (int) $course->id,
                );
            }
        }

        foreach ($this->graph->courseCycles($course) as $cycle) {
            $report->error(
                'course.prerequisite_cycle_course',
                'Rantai prasyarat antar-course melingkar: '.implode(' → ', $cycle['labels']).'.',
                'prerequisite',
                (int) $course->id,
                'Putus salah satu tautan pada rantai tersebut.',
                ['cycle' => $cycle['path']],
            );
        }
    }

    private function validateCertificate(LmsCourse $course, ValidationReport $report): void
    {
        if (! (bool) $course->certificate_enabled) {
            return;
        }

        if ($course->certificate_template_id === null) {
            $report->warning('course.certificate_template_missing', 'Sertifikat aktif tanpa template; desain bawaan akan dipakai.', 'certificate', (int) $course->id);
        }

        $minProgress = $course->certificate_min_progress;

        if ($minProgress !== null && ((float) $minProgress <= 0 || (float) $minProgress > 100)) {
            $report->error('course.certificate_min_progress_range', 'Syarat progres sertifikat harus di antara 1 dan 100.', 'certificate', (int) $course->id);
        }

        $minGrade = $course->certificate_min_grade;

        if ($minGrade !== null && (float) $minGrade < 0) {
            $report->error('course.certificate_min_grade_invalid', 'Nilai minimum sertifikat tidak boleh negatif.', 'certificate', (int) $course->id);
        }

        if ((float) ($course->certificate_min_progress ?? 0) > 0 && (float) ($course->certificate_min_grade ?? 0) > 0) {
            $report->info('course.certificate_two_requirements', 'Sertifikat mensyaratkan progres dan nilai sekaligus; pastikan keduanya bisa dicapai bersamaan.', 'certificate', (int) $course->id);
        }
    }
}
