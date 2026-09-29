<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningItem;

/** Validasi aturan penyelesaian (§17). */
class CompletionValidator
{
    public function validateItem(LmsLearningItem $item): ValidationReport
    {
        $report = new ValidationReport;

        $type = $item->completion_type;
        $value = $type instanceof CompletionType ? $type->value : (string) $type;

        if ($value === CompletionType::PASS->value && (float) ($item->completion_min_grade ?? 0) <= 0) {
            $report->error(
                'completion.min_grade_missing',
                'Item "'.$item->title.'" menuntut kelulusan tetapi ambang nilainya belum diisi.',
                'completion',
                (int) $item->id,
            );
        }

        if ($item->item_type === ItemType::ACTIVITY && $value === CompletionType::VIEW->value) {
            $report->warning(
                'completion.activity_view_only',
                'Aktivitas "'.$item->title.'" dianggap selesai hanya dengan dibuka.',
                'completion',
                (int) $item->id,
                'Gunakan aturan SUBMIT atau PASS untuk aktivitas yang dinilai.',
            );
        }

        if ($item->item_type === ItemType::RESOURCE && in_array($value, [CompletionType::SUBMIT->value, CompletionType::PASS->value], true)) {
            $report->error(
                'completion.resource_requires_submission',
                'Materi "'.$item->title.'" tidak punya pengumpulan, tetapi aturan penyelesaiannya menuntut hal itu.',
                'completion',
                (int) $item->id,
            );
        }

        return $report;
    }

    public function validateCourse(LmsCourse $course): ValidationReport
    {
        $report = new ValidationReport;

        foreach ($course->items()->with('activity')->get() as $item) {
            $report->merge($this->validateItem($item));
        }

        $required = $course->items()->where('is_required', true)->count();

        if ($required === 0 && $course->completion_rule?->value === 'ALL_REQUIRED_ACTIVITIES') {
            $report->error(
                'completion.no_required_item',
                'Aturan penyelesaian menuntut seluruh aktivitas wajib, tetapi tidak ada item yang ditandai wajib.',
                'completion',
                (int) $course->id,
                'Tandai minimal satu item sebagai wajib atau ubah aturan penyelesaian.',
            );
        }

        return $report;
    }
}
