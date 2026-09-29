<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;

/** Validasi bagian/section (§17). */
class SectionValidator
{
    public function validate(LmsCourseSection $section): ValidationReport
    {
        $report = new ValidationReport;

        if (! filled($section->title)) {
            $report->error('section.title_missing', 'Judul bagian wajib diisi.', 'section', (int) $section->id);
        }

        $items = $section->items()->count();

        if ($items === 0) {
            $report->warning('section.empty', 'Bagian "'.$section->title.'" belum berisi materi.', 'section', (int) $section->id);
        }

        $duplicate = LmsCourseSection::query()
            ->where('course_id', $section->course_id)
            ->where('id', '!=', $section->id)
            ->where('title', $section->title)
            ->exists();

        if ($duplicate) {
            $report->warning('section.duplicate_title', 'Ada bagian lain dengan judul yang sama.', 'section', (int) $section->id);
        }

        return $report;
    }

    public function validateCourse(LmsCourse $course): ValidationReport
    {
        $report = new ValidationReport;

        foreach ($course->sections()->get() as $section) {
            $report->merge($this->validate($section));
        }

        return $report;
    }
}
