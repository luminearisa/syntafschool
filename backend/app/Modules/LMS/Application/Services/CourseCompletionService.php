<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\PrerequisiteType;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsItemPrerequisite;
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

        // Phase 4 (§3): dependency bertipe yang ditulis guru lewat builder
        // prasyarat. Dievaluasi di sini juga supaya tetap satu mesin.
        return $this->satisfiesTypedPrerequisites($enrollment, $item);
    }

    /**
     * Prasyarat bertipe (§3) — COMPLETION, GRADE, PASSING_GRADE,
     * ACTIVITY_RESULT, dan DATE.
     *
     * Hanya membaca baris `lms_item_prerequisites` yang aktif; baris tanpa
     * rujukan valid diabaikan agar salah konfigurasi tidak mengunci materi.
     */
    public function satisfiesTypedPrerequisites(LmsEnrollment $enrollment, LmsLearningItem $item): AccessDecision
    {
        $rows = $this->prerequisiteRowsFor($item);

        if ($rows->isEmpty()) {
            return AccessDecision::allow();
        }

        $completed = $this->completedItemIds($enrollment);

        foreach ($rows as $row) {
            $decision = $this->evaluatePrerequisiteRow($enrollment, $row, $completed);

            if (! $decision->allowed) {
                return $decision;
            }
        }

        return AccessDecision::allow();
    }

    /**
     * Baris prasyarat yang berlaku untuk sebuah item — baik yang menunjuk
     * langsung ke item, maupun lewat aktivitas milik item tersebut.
     *
     * @return Collection<int, LmsItemPrerequisite>
     */
    public function prerequisiteRowsFor(LmsLearningItem $item): Collection
    {
        $activityId = $item->relationLoaded('activity')
            ? $item->activity?->id
            : $item->activity()->value('id');

        return LmsItemPrerequisite::query()
            ->where('is_enforced', true)
            ->where(function ($query) use ($item, $activityId) {
                $query->where('item_id', $item->id);

                if ($activityId !== null) {
                    $query->orWhere('activity_id', $activityId);
                }
            })
            ->get();
    }

    /** Nilai (skala 0–100) sebuah item atau aktivitas prasyarat. */
    public function gradeFor(LmsEnrollment $enrollment, ?int $itemId, ?int $activityId): ?float
    {
        if ($itemId !== null) {
            $fromItem = LmsActivityCompletion::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('learning_item_id', $itemId)
                ->whereNotNull('grade_value')
                ->orderByDesc('grade_value')
                ->value('grade_value');

            if ($fromItem !== null) {
                return (float) $fromItem;
            }
        }

        $gradeQuery = LmsActivityGrade::query()->where('user_id', $enrollment->user_id);

        if ($activityId !== null) {
            $gradeQuery->where('activity_id', $activityId);
        } elseif ($itemId !== null) {
            $gradeQuery->where('learning_item_id', $itemId);
        } else {
            return null;
        }

        $grade = $gradeQuery->orderByDesc('grade')->first();

        return $grade?->percent();
    }

    /** Teks siap tampil untuk satu baris prasyarat (§3). */
    public function describePrerequisite(LmsItemPrerequisite $row): string
    {
        $reference = $row->prerequisite_activity?->title
            ?? $row->prerequisiteItem?->title
            ?? 'materi sebelumnya';

        return match ($row->type) {
            PrerequisiteType::COMPLETION => 'Selesaikan "'.$reference.'" terlebih dahulu.',
            PrerequisiteType::GRADE => '"'.$reference.'" harus mencapai nilai minimal '.rtrim(rtrim((string) $row->threshold, '0'), '.').'.',
            PrerequisiteType::PASSING_GRADE => 'Lulus "'.$reference.'" (nilai kelulusan).',
            PrerequisiteType::ACTIVITY_RESULT => 'Hasil "'.$reference.'" harus '.strtolower((string) ($row->result_value ?: 'PASS')).'.',
            PrerequisiteType::DATE => 'Terbuka pada '.($row->release_at?->translatedFormat('d F Y H:i') ?? 'tanggal yang ditentukan').'.',
        };
    }

    protected function evaluatePrerequisiteRow(
        LmsEnrollment $enrollment,
        LmsItemPrerequisite $row,
        array $completed,
    ): AccessDecision {
        $deny = fn (string $reason, string $message, ?int $blocked = null) => AccessDecision::deny($reason, $message, $blocked);

        switch ($row->type) {
            case PrerequisiteType::DATE:
                if ($row->release_at !== null && $row->release_at->isFuture()) {
                    return $deny('prerequisite_release_date', $this->describePrerequisite($row));
                }

                return AccessDecision::allow();

            case PrerequisiteType::COMPLETION:
                $itemId = (int) ($row->prerequisite_item_id ?? 0);

                if ($itemId > 0 && ! in_array($itemId, $completed, true)) {
                    return $deny('prerequisite_incomplete', $this->describePrerequisite($row), $itemId);
                }

                $activityId = (int) ($row->prerequisite_activity_id ?? 0);

                if ($itemId === 0 && $activityId > 0) {
                    $done = LmsActivityCompletion::query()
                        ->where('enrollment_id', $enrollment->id)
                        ->where('status', 'COMPLETE')
                        ->whereIn('learning_item_id', $this->activityItemIds($activityId))
                        ->exists();

                    if (! $done) {
                        return $deny('prerequisite_incomplete', $this->describePrerequisite($row));
                    }
                }

                return AccessDecision::allow();

            case PrerequisiteType::GRADE:
            case PrerequisiteType::PASSING_GRADE:
                $value = $this->gradeFor($enrollment, $row->prerequisite_item_id, $row->prerequisite_activity_id);
                $threshold = $row->type === PrerequisiteType::PASSING_GRADE
                    ? (float) ($row->threshold ?? $this->passingThreshold())
                    : (float) $row->threshold;

                if ($value === null || $value < $threshold) {
                    return $deny('prerequisite_grade_not_met', $this->describePrerequisite($row));
                }

                return AccessDecision::allow();

            case PrerequisiteType::ACTIVITY_RESULT:
                $expected = strtoupper((string) ($row->result_value ?: 'PASS'));
                $itemId = (int) ($row->prerequisite_item_id ?? 0);
                $activityId = (int) ($row->prerequisite_activity_id ?? 0);

                $isComplete = $itemId > 0
                    ? in_array($itemId, $completed, true)
                    : LmsActivityCompletion::query()
                        ->where('enrollment_id', $enrollment->id)
                        ->where('status', 'COMPLETE')
                        ->whereIn('learning_item_id', $this->activityItemIds($activityId))
                        ->exists();

                if (! $isComplete) {
                    return $deny('prerequisite_result_not_met', $this->describePrerequisite($row));
                }

                if ($expected === 'PASS') {
                    $value = $this->gradeFor($enrollment, $itemId ?: null, $activityId ?: null);

                    if ($value !== null && $value < $this->passingThreshold()) {
                        return $deny('prerequisite_result_not_met', $this->describePrerequisite($row));
                    }
                }

                return AccessDecision::allow();
        }

        return AccessDecision::allow();
    }

    /** @return list<int> */
    protected function activityItemIds(int $activityId): array
    {
        $itemId = \App\Modules\LMS\Models\LmsActivity::query()->whereKey($activityId)->value('learning_item_id');

        return $itemId !== null ? [(int) $itemId] : [];
    }

    protected function passingThreshold(): float
    {
        return (float) config('lms.certificate.passing_grade', 70);
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
