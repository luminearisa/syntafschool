<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Models\LmsCertificateTemplate;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsStudentCompetency;

/**
 * Validasi konfigurasi sertifikat (§17, §21–§25).
 *
 * Sertifikat yang syaratnya mustahil dicapai membuat siswa kehilangan
 * pengakuan atas pekerjaan yang sudah diselesaikannya.
 */
class CertificateValidator
{
    public function validate(LmsCourse $course): ValidationReport
    {
        $report = new ValidationReport;

        if (! (bool) $course->certificate_enabled) {
            return $report;
        }

        if ($course->certificate_template_id === null) {
            $report->warning(
                'certificate.template_missing',
                'Sertifikat aktif tanpa template; desain bawaan akan dipakai.',
                'certificate',
                (int) $course->id,
            );
        } else {
            $template = LmsCertificateTemplate::query()->find($course->certificate_template_id);

            if ($template === null) {
                $report->error(
                    'certificate.template_not_found',
                    'Template sertifikat yang dipilih sudah tidak ada.',
                    'certificate',
                    (int) $course->id,
                    'Pilih template lain pada tab Sertifikat.',
                );
            } elseif ($template->trashed()) {
                $report->error('certificate.template_archived', 'Template sertifikat yang dipilih sudah diarsipkan.', 'certificate', (int) $course->id);
            }
        }

        $minProgress = $course->certificate_min_progress;
        $minGrade = $course->certificate_min_grade;

        if ($minProgress === null && $minGrade === null && $course->certificate_required_competency_id === null) {
            $report->error(
                'certificate.no_requirement',
                'Sertifikat aktif tanpa satu pun syarat; sertifikat akan terbit untuk semua peserta sejak awal.',
                'certificate',
                (int) $course->id,
                'Tetapkan syarat ketuntasan, nilai minimum, atau kompetensi wajib.',
            );
        }

        if ((float) $minProgress === 0.0 && (float) $minGrade === 0.0) {
            $report->warning('certificate.zero_requirement', 'Syarat sertifikat bernilai nol sehingga tidak bermakna.', 'certificate', (int) $course->id);
        }

        if ($course->certificate_required_competency_id !== null) {
            $competency = $course->certificate_required_competency_id;
            $linked = $course->competencies()->where('lms_competencies.id', $competency)->exists();

            if (! $linked) {
                $report->error(
                    'certificate.competency_not_linked',
                    'Kompetensi wajib sertifikat belum dipetakan ke course ini.',
                    'certificate',
                    (int) $course->id,
                    'Petakan kompetensi tersebut pada tab Kompetensi.',
                );
            }

            if (LmsStudentCompetency::query()->where('course_id', $course->id)->where('competency_id', $competency)->exists() === false) {
                $report->info('certificate.competency_no_assessment', 'Kompetensi wajib sertifikat belum pernah dinilai pada course ini.', 'certificate', (int) $course->id);
            }
        }

        if ($minGrade !== null && $minGrade > 0) {
            $gradebook = $course->gradebook()->first();

            if ($gradebook === null) {
                $report->error(
                    'certificate.gradebook_missing',
                    'Sertifikat mensyaratkan nilai minimum, tetapi course belum memiliki gradebook.',
                    'certificate',
                    (int) $course->id,
                );
            }
        }

        return $report;
    }
}
