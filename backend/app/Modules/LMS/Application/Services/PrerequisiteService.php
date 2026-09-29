<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\PrerequisiteType;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsItemPrerequisite;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prasyarat antar materi/aktivitas (§3) — sisi penulisan & validasi.
 *
 * Evaluasi TIDAK dilakukan di sini: satu-satunya tempat menghitung kelayakan
 * adalah `CourseCompletionService` (mesin completion Phase 1). Service ini
 * menyiapkan data, mencegah siklus, dan menyediakan teks siap tampil.
 */
class PrerequisiteService
{
    public function __construct(
        private readonly CourseCompletionService $completions,
        private readonly LearningEventService $events,
    ) {}

    /** @return Collection<int, LmsItemPrerequisite> */
    public function forItem(LmsLearningItem $item): Collection
    {
        return $this->completions->prerequisiteRowsFor($item);
    }

    /**
     * Simpan prasyarat untuk satu materi.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, LmsItemPrerequisite>
     */
    public function syncForItem(LmsLearningItem $item, array $rows, ?LmsActor $actor = null): Collection
    {
        $course = $item->course;

        if ($course === null) {
            throw ValidationException::withMessages(['item' => 'Materi tidak terhubung ke course mana pun.']);
        }

        $normalized = $this->normalizeRows($item, $rows);

        $this->assertNoCycle($item, $normalized);

        LmsItemPrerequisite::query()->where('item_id', $item->id)->delete();

        foreach ($normalized as $row) {
            LmsItemPrerequisite::query()->create($row + [
                'course_id' => $course->id,
                'item_id' => $item->id,
                'created_by' => $actor?->id,
            ]);
        }

        $this->events->audit($course, 'prerequisites_updated', [
            'item_id' => (int) $item->id,
            'count' => count($normalized),
        ], $actor);

        return $this->forItem($item);
    }

    public function add(LmsLearningItem $item, array $data, ?LmsActor $actor = null): LmsItemPrerequisite
    {
        $course = $item->course;

        $row = $this->normalizeRow($item, $data);

        $this->assertNoCycle($item, [$row]);

        $created = LmsItemPrerequisite::query()->create($row + [
            'course_id' => $course?->id,
            'item_id' => $item->id,
            'created_by' => $actor?->id,
        ]);

        if ($course !== null) {
            $this->events->audit($course, 'prerequisite_added', ['item_id' => (int) $item->id, 'type' => $created->type?->value], $actor);
        }

        return $created;
    }

    public function delete(LmsItemPrerequisite $row, ?LmsActor $actor = null): void
    {
        $course = $row->course;
        $row->delete();

        if ($course !== null) {
            $this->events->audit($course, 'prerequisite_deleted', ['prerequisite_id' => (int) $row->id], $actor);
        }
    }

    /**
     * Pratinjau keputusan untuk guru: memakai mesin yang sama dengan siswa,
     * sehingga guru melihat alasan kunci yang persis sama.
     */
    public function preview(LmsLearningItem $item, LmsEnrollment $enrollment): AccessDecision
    {
        return $this->completions->satisfiesPrerequisites($enrollment, $item);
    }

    /** @return list<array<string, mixed>> */
    public function describeForItem(LmsLearningItem $item): array
    {
        return $this->forItem($item)->map(fn (LmsItemPrerequisite $row) => [
            'id' => (int) $row->id,
            'type' => $row->type?->value,
            'type_label' => $row->type?->label(),
            'threshold' => $row->threshold !== null ? (float) $row->threshold : null,
            'result_value' => $row->result_value,
            'release_at' => $row->release_at?->toIso8601String(),
            'prerequisite_item' => $row->prerequisiteItem !== null ? [
                'id' => (int) $row->prerequisiteItem->id,
                'title' => $row->prerequisiteItem->title,
            ] : null,
            'prerequisite_activity' => $row->prerequisiteActivity !== null ? [
                'id' => (int) $row->prerequisiteActivity->id,
                'title' => $row->prerequisiteActivity->title,
            ] : null,
            'is_enforced' => (bool) $row->is_enforced,
            'description' => $this->completions->describePrerequisite($row),
        ])->all();
    }

    /* ---------------------------------------------------------------------
     | Validasi & normalisasi
     * ------------------------------------------------------------------- */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function normalizeRows(LmsLearningItem $item, array $rows): array
    {
        $course = $item->course;

        return array_values(array_map(function (array $row) use ($item, $course) {
            $normalized = $this->normalizeRow($item, $row);

            if ($course !== null && (int) ($normalized['prerequisite_item_id'] ?? 0) === (int) $item->id) {
                throw ValidationException::withMessages([
                    'prerequisites' => 'Materi tidak dapat menjadi prasyarat bagi dirinya sendiri.',
                ]);
            }

            return $normalized;
        }, $rows));
    }

    /** @return array<string, mixed> */
    protected function normalizeRow(LmsLearningItem $item, array $row): array
    {
        $type = PrerequisiteType::tryFrom((string) ($row['type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages(['type' => 'Jenis prasyarat tidak dikenal.']);
        }

        $prerequisiteItemId = isset($row['prerequisite_item_id']) && $row['prerequisite_item_id'] !== null
            ? (int) $row['prerequisite_item_id']
            : null;

        $prerequisiteActivityId = isset($row['prerequisite_activity_id']) && $row['prerequisite_activity_id'] !== null
            ? (int) $row['prerequisite_activity_id']
            : null;

        if ($type->requiresReference() && $prerequisiteItemId === null && $prerequisiteActivityId === null) {
            throw ValidationException::withMessages([
                'prerequisite_item_id' => 'Prasyarat tipe '.$type->label().' memerlukan materi atau aktivitas rujukan.',
            ]);
        }

        if ($prerequisiteItemId !== null) {
            $belongs = LmsLearningItem::query()
                ->whereKey($prerequisiteItemId)
                ->where('course_id', $item->course_id)
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'prerequisite_item_id' => 'Materi prasyarat harus berasal dari course yang sama.',
                ]);
            }
        }

        $threshold = $row['threshold'] ?? null;

        if (in_array($type, [PrerequisiteType::GRADE, PrerequisiteType::PASSING_GRADE], true) && $threshold === null) {
            throw ValidationException::withMessages(['threshold' => 'Nilai minimum wajib diisi untuk prasyarat nilai.']);
        }

        if ($type === PrerequisiteType::DATE && empty($row['release_at'])) {
            throw ValidationException::withMessages(['release_at' => 'Tanggal rilis wajib diisi untuk prasyarat tanggal.']);
        }

        return [
            'activity_id' => isset($row['activity_id']) && $row['activity_id'] !== null ? (int) $row['activity_id'] : null,
            'prerequisite_item_id' => $prerequisiteItemId,
            'prerequisite_activity_id' => $prerequisiteActivityId,
            'type' => $type->value,
            'threshold' => $threshold !== null ? (float) $threshold : null,
            'result_value' => $row['result_value'] ?? ($type === PrerequisiteType::ACTIVITY_RESULT ? 'PASS' : null),
            'release_at' => $row['release_at'] ?? null,
            'is_enforced' => (bool) ($row['is_enforced'] ?? true),
            'note' => $row['note'] ?? null,
        ];
    }

    /**
     * Tolak prasyarat melingkar (§3).
     *
     * Graf kecil, jadi penelusuran sederhana sudah cukup dan jauh lebih mudah
     * ditelusuri bila suatu hari ada keluhan "materi terkunci selamanya".
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function assertNoCycle(LmsLearningItem $item, array $rows): void
    {
        foreach ($rows as $row) {
            $target = (int) ($row['prerequisite_item_id'] ?? 0);

            if ($target === 0) {
                continue;
            }

            if ($this->dependsOn($target, (int) $item->id)) {
                throw ValidationException::withMessages([
                    'prerequisites' => 'Prasyarat ini membuat lingkaran ketergantungan antar materi.',
                ]);
            }
        }
    }

    /** Apakah `$itemId` (langsung atau tidak langsung) bergantung pada `$targetId`? */
    protected function dependsOn(int $itemId, int $targetId, int $depth = 0): bool
    {
        if ($depth > 12) {
            return true;
        }

        if ($itemId === $targetId) {
            return true;
        }

        $parents = LmsItemPrerequisite::query()
            ->where('item_id', $itemId)
            ->whereNotNull('prerequisite_item_id')
            ->pluck('prerequisite_item_id');

        foreach ($parents as $parentId) {
            if ($this->dependsOn((int) $parentId, $targetId, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
