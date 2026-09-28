<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Collection;

/**
 * Aturan "kapan sebuah item dianggap tuntas" (§22) dan prasyarat antar item (§21).
 *
 * Dipisahkan dari `CourseProgressService` supaya aturan kelulusan/penyelesaian
 * tidak tercampur dengan aritmetika persentase — dan supaya Phase 2 (Assignment,
 * Quiz) hanya perlu memanggil satu tempat untuk menandai item tuntas.
 */
class CourseCompletionService
{
    /** @return Collection<int, LmsActivityCompletion> */
    public function completions(LmsEnrollment $enrollment): Collection
    {
        return LmsActivityCompletion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'COMPLETE')
            ->get()
            ->keyBy('learning_item_id');
    }

    /** @return list<int> */
    public function completedItemIds(LmsEnrollment $enrollment): array
    {
        return array_map('intval', $this->completions($enrollment)->keys()->all());
    }

    public function isComplete(LmsEnrollment $enrollment, int $itemId): bool
    {
        return LmsActivityCompletion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('learning_item_id', $itemId)
            ->where('status', 'COMPLETE')
            ->exists();
    }

    /**
     * Sekali jalan: buat atau perbarui catatan penyelesaian.
     * Nilai (`grade_value`) hanya relevan untuk completion_type PASS/SUBMIT.
     */
    public function markComplete(
        LmsEnrollment $enrollment,
        LmsLearningItem $item,
        ?float $grade = null,
        string $source = 'manual',
        ?int $actorId = null,
    ): LmsActivityCompletion {
        $type = $item->completion_type ?? CompletionType::MANUAL;

        return LmsActivityCompletion::updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'learning_item_id' => $item->id,
            ],
            [
                'course_id' => $item->course_id,
                'user_id' => $enrollment->user_id,
                'completion_type' => $type->value,
                'status' => 'COMPLETE',
                'grade_value' => $grade,
                'passed' => $this->passesItem($item, $grade),
                'completed_at' => now(),
                'completed_by' => $actorId ?? $enrollment->user_id,
                'metadata' => ['source' => $source],
            ],
        );
    }

    public function unmark(LmsEnrollment $enrollment, int $itemId): void
    {
        LmsActivityCompletion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('learning_item_id', $itemId)
            ->delete();
    }

    /** Nilai minimum item (bila diisi) menentukan "lulus". */
    public function passesItem(LmsLearningItem $item, ?float $grade): ?bool
    {
        if ($item->completion_type !== CompletionType::PASS) {
            return $grade !== null ? $grade > 0 : null;
        }

        if ($grade === null) {
            return false;
        }

        return $item->completion_min_grade === null || (float) $grade >= (float) $item->completion_min_grade;
    }

    /**
     * Apakah seluruh prasyarat item ini sudah terpenuhi (§21)?
     *
     * Aturan yang dikenali: `require_previous_item`, `required_item_ids`,
     * `required_section_ids`, `required_grade`, `required_completion`.
     * Semua bersifat opsional — item tanpa aturan selalu terbuka.
     */
    public function satisfiesPrerequisites(LmsEnrollment $enrollment, LmsLearningItem $item): AccessDecision
    {
        $rules = $item->availabilityRules();

        if ($rules === []) {
            return AccessDecision::allow();
        }

        $completed = $this->completedItemIds($enrollment);

        if (($rules['required_completion'] ?? false) === true && $completed === []) {
            return AccessDecision::deny(
                'required_completion',
                'Selesaikan minimal satu aktivitas terlebih dahulu.',
            );
        }

        if (($rules['require_previous_item'] ?? false) === true) {
            $previous = $this->previousItem($item);

            if ($previous !== null && ! in_array((int) $previous->id, $completed, true)) {
                return AccessDecision::deny(
                    'previous_item_incomplete',
                    'Selesaikan "'.$previous->title.'" terlebih dahulu.',
                    (int) $previous->id,
                );
            }
        }

        foreach ((array) ($rules['required_item_ids'] ?? []) as $requiredId) {
            $requiredId = (int) $requiredId;

            if ($requiredId > 0 && ! in_array($requiredId, $completed, true)) {
                $title = LmsLearningItem::query()->whereKey($requiredId)->value('title') ?? 'aktivitas sebelumnya';

                return AccessDecision::deny('required_item_incomplete', 'Selesaikan "'.$title.'" terlebih dahulu.', $requiredId);
            }
        }

        $requiredSections = array_map('intval', (array) ($rules['required_section_ids'] ?? []));

        if ($requiredSections !== []) {
            $completedCounts = LmsActivityCompletion::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', 'COMPLETE')
                ->whereIn('learning_item_id', LmsLearningItem::query()
                    ->whereIn('section_id', $requiredSections)
                    ->where('is_required', true)
                    ->select('id'))
                ->count();

            $requiredCount = LmsLearningItem::query()
                ->whereIn('section_id', $requiredSections)
                ->where('is_required', true)
                ->where('completion_type', '!=', CompletionType::NONE->value)
                ->count();

            if ($requiredCount > 0 && $completedCounts < $requiredCount) {
                return AccessDecision::deny(
                    'required_section_incomplete',
                    'Selesaikan bagian prasyarat terlebih dahulu ('.$completedCounts.'/'.$requiredCount.').',
                );
            }
        }

        if (($rules['required_grade'] ?? null) !== null) {
            $grade = LmsActivityCompletion::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNotNull('grade_value')
                ->orderByDesc('grade_value')
                ->value('grade_value');

            if ($grade === null || (float) $grade < (float) $rules['required_grade']) {
                return AccessDecision::deny(
                    'required_grade_not_met',
                    'Nilai minimum '.$rules['required_grade'].' belum tercapai.',
                );
            }
        }

        return AccessDecision::allow();
    }

    /** Item sebelumnya dalam urutan course (lintas section). */
    public function previousItem(LmsLearningItem $item): ?LmsLearningItem
    {
        return $this->orderedItems((int) $item->course_id)
            ->takeWhile(fn (LmsLearningItem $candidate) => (int) $candidate->id !== (int) $item->id)
            ->last();
    }

    /**
     * Seluruh item course dalam urutan tampil (section lalu item).
     *
     * @return Collection<int, LmsLearningItem>
     */
    public function orderedItems(int $courseId, bool $onlyVisible = true): Collection
    {
        return LmsLearningItem::query()
            ->with('section:id,title,sort_order,visibility')
            ->where('course_id', $courseId)
            ->when($onlyVisible, fn ($q) => $q->where('visibility', 'VISIBLE'))
            ->orderBy('section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->sortBy([
                fn (LmsLearningItem $a, LmsLearningItem $b) => ($a->section?->sort_order ?? 0) <=> ($b->section?->sort_order ?? 0),
                fn (LmsLearningItem $a, LmsLearningItem $b) => $a->sort_order <=> $b->sort_order,
            ])
            ->values();
    }

    public function nextItemInCourse(LmsLearningItem $item): ?LmsLearningItem
    {
        $items = $this->orderedItems((int) $item->course_id);
        $index = $items->search(fn (LmsLearningItem $candidate) => (int) $candidate->id === (int) $item->id);

        if ($index === false) {
            return null;
        }

        return $items->get($index + 1);
    }
}
