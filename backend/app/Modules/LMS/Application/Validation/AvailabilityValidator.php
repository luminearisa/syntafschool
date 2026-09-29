<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\DripReleaseType;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsDripRule;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Carbon;

/**
 * Validasi aturan ketersediaan & drip content (§17).
 *
 * Aturan yang saling bertabrakan (mis. "tersedia mulai" lebih lambat daripada
 * tenggat, atau drip menunjuk item yang sudah dihapus) membuat siswa melihat
 * materi yang tidak pernah terbuka.
 */
class AvailabilityValidator
{
    public function validateItem(LmsLearningItem $item): ValidationReport
    {
        $report = new ValidationReport;
        $availability = (array) ($item->availability ?? []);

        $from = $availability['available_from'] ?? null;
        $until = $availability['available_until'] ?? $availability['due_at'] ?? null;

        if ($from !== null && $until !== null) {
            try {
                if (Carbon::parse($until)->lt(Carbon::parse($from))) {
                    $report->error(
                        'availability.window_inverted',
                        'Item "'.$item->title.'" ditutup sebelum waktunya dibuka.',
                        'availability',
                        (int) $item->id,
                    );
                }
            } catch (\Throwable) {
                $report->error('availability.date_invalid', 'Format tanggal ketersediaan item "'.$item->title.'" tidak dapat dibaca.', 'availability', (int) $item->id);
            }
        }

        $required = (array) ($availability['required_item_ids'] ?? []);

        foreach ($required as $requiredId) {
            $exists = LmsLearningItem::query()->whereKey((int) $requiredId)->exists();

            if (! $exists) {
                $report->error(
                    'availability.required_item_missing',
                    'Item "'.$item->title.'" mensyaratkan item yang sudah dihapus (#'.$requiredId.').',
                    'availability',
                    (int) $item->id,
                    'Perbarui aturan ketersediaan item tersebut.',
                );
            }
        }

        if ($item->is_required && ($availability['visible_from'] ?? null) !== null && $until !== null) {
            $report->info('availability.required_with_window', 'Item wajib ini hanya tersedia pada rentang waktu tertentu; pastikan rentangnya cukup.', 'availability', (int) $item->id);
        }

        return $report;
    }

    public function validateCourse(LmsCourse $course): ValidationReport
    {
        $report = new ValidationReport;

        foreach ($course->items()->get() as $item) {
            $report->merge($this->validateItem($item));
        }

        foreach (LmsDripRule::query()->where('course_id', $course->id)->with(['item', 'section'])->get() as $rule) {
            /** @var LmsDripRule $rule */
            $type = $rule->release_type instanceof DripReleaseType ? $rule->release_type : DripReleaseType::tryFrom((string) $rule->release_type);

            if ($type === null) {
                $report->error('availability.drip_type_invalid', 'Aturan drip memiliki jenis rilis yang tidak dikenali.', 'availability', (int) $rule->id);

                continue;
            }

            if ($rule->item_id === null && $rule->section_id === null) {
                $report->error('availability.drip_target_missing', 'Aturan drip tidak menunjuk item atau bagian mana pun.', 'availability', (int) $rule->id);
            }

            if ($rule->item_id !== null && $rule->item === null) {
                $report->error('availability.drip_item_missing', 'Aturan drip menunjuk item yang sudah dihapus.', 'availability', (int) $rule->id);
            }

            if ($type === DripReleaseType::SPECIFIC_DATE && $rule->release_at === null) {
                $report->error('availability.drip_date_missing', 'Drip jenis tanggal memerlukan waktu rilis.', 'availability', (int) $rule->id);
            }

            if ($type === DripReleaseType::DAYS_AFTER_ENROLLMENT && (int) $rule->days_after_enrollment <= 0) {
                $report->error('availability.drip_days_invalid', 'Drip jenis selang hari memerlukan jumlah hari lebih dari 0.', 'availability', (int) $rule->id);
            }
        }

        return $report;
    }
}
