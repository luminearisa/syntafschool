<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\DripReleaseType;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsDripRule;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Drip content (§4) — kapan sebuah bagian/materi boleh dibuka.
 *
 * Service ini hanya *menghitung tanggal buka*; keputusan akhir tetap diambil
 * jalur availability yang sudah ada (`CourseAccessService` untuk player,
 * `ActivityAvailabilityService` untuk halaman aktivitas). Dengan begitu tidak
 * ada mesin akses kedua yang bisa berbeda pendapat.
 */
class DripContentService
{
    public function __construct(
        private readonly CourseCompletionService $completions,
        private readonly LearningEventService $events,
    ) {}

    /** @return Collection<int, LmsDripRule> */
    public function rulesFor(LmsCourse $course): Collection
    {
        return LmsDripRule::query()
            ->where('course_id', $course->id)
            ->active()
            ->orderBy('id')
            ->get();
    }

    /**
     * Aturan yang berlaku untuk sebuah bagian atau materi.
     *
     * Aturan paling spesifik menang: item → aktivitas → bagian → course.
     */
    public function applicableRule(
        ?LmsCourseSection $section = null,
        ?LmsLearningItem $item = null,
        ?int $activityId = null,
    ): ?LmsDripRule {
        $query = LmsDripRule::query()->active();

        if ($item !== null) {
            $rule = (clone $query)->where('item_id', $item->id)->orderByDesc('id')->first();

            if ($rule !== null) {
                return $rule;
            }

            $section = $section ?? $item->section;
        }

        if ($activityId !== null) {
            $rule = (clone $query)->where('activity_id', $activityId)->orderByDesc('id')->first();

            if ($rule !== null) {
                return $rule;
            }
        }

        if ($section !== null) {
            $rule = (clone $query)->whereNull('item_id')->whereNull('activity_id')
                ->where('section_id', $section->id)->orderByDesc('id')->first();

            if ($rule !== null) {
                return $rule;
            }
        }

        return null;
    }

    /** Kapan bagian/materi ini terbuka? `null` berarti tanpa penundaan. */
    public function unlockDate(
        LmsCourse $course,
        ?LmsEnrollment $enrollment,
        ?LmsCourseSection $section = null,
        ?LmsLearningItem $item = null,
        ?int $activityId = null,
    ): ?Carbon {
        $rule = $this->applicableRule($section, $item, $activityId);

        if ($rule === null) {
            return null;
        }

        return $this->resolveUnlockDate($rule, $course, $enrollment);
    }

    /**
     * Evaluasi drip pada satu titik akses. Mengembalikan `allow()` bila belum
     * waktunya dibuka — termasuk pesan siap tampil beserta tanggalnya.
     */
    public function evaluate(
        LmsCourse $course,
        ?LmsEnrollment $enrollment,
        ?LmsCourseSection $section = null,
        ?LmsLearningItem $item = null,
        ?int $activityId = null,
    ): AccessDecision {
        $rule = $this->applicableRule($section, $item, $activityId);

        if ($rule === null) {
            return AccessDecision::allow();
        }

        if ($rule->release_type === DripReleaseType::IMMEDIATE) {
            return AccessDecision::allow();
        }

        if ($rule->release_type === DripReleaseType::ON_COMPLETION) {
            return $this->evaluateOnCompletion($rule, $enrollment);
        }

        if ($enrollment === null) {
            return AccessDecision::deny('not_enrolled', 'Anda belum terdaftar pada course ini.');
        }

        $unlockAt = $this->resolveUnlockDate($rule, $course, $enrollment);

        if ($unlockAt === null || $unlockAt->isFuture() === false) {
            return AccessDecision::allow();
        }

        return AccessDecision::deny(
            'drip_locked',
            'Materi ini dibuka bertahap pada '.$unlockAt->translatedFormat('d F Y H:i').'.',
        );
    }

    /** Kunci karena prasyarat penyelesaian (bukan tanggal). */
    protected function evaluateOnCompletion(LmsDripRule $rule, ?LmsEnrollment $enrollment): AccessDecision
    {
        if ($enrollment === null) {
            return AccessDecision::deny('not_enrolled', 'Anda belum terdaftar pada course ini.');
        }

        $gate = $this->gatingItem($rule);

        if ($gate === null) {
            return AccessDecision::allow();
        }

        if (in_array((int) $gate->id, $this->completions->completedItemIds($enrollment), true)) {
            return AccessDecision::allow();
        }

        return AccessDecision::deny(
            'drip_waiting_completion',
            'Materi ini terbuka setelah Anda menyelesaikan "'.$gate->title.'".',
            (int) $gate->id,
        );
    }

    /** Materi pemicu: `after_item_id`, atau materi sebelumnya dalam course. */
    public function gatingItem(LmsDripRule $rule): ?LmsLearningItem
    {
        if ($rule->after_item_id !== null) {
            return LmsLearningItem::query()->find($rule->after_item_id);
        }

        if ($rule->item_id !== null) {
            return $this->completions->previousItem(LmsLearningItem::query()->findOrFail($rule->item_id));
        }

        if ($rule->section_id !== null) {
            $firstItem = LmsLearningItem::query()
                ->where('section_id', $rule->section_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            return $firstItem !== null ? $this->completions->previousItem($firstItem) : null;
        }

        return null;
    }

    protected function resolveUnlockDate(LmsDripRule $rule, LmsCourse $course, ?LmsEnrollment $enrollment): ?Carbon
    {
        return match ($rule->release_type) {
            DripReleaseType::IMMEDIATE => null,

            DripReleaseType::SPECIFIC_DATE => $rule->release_at,

            DripReleaseType::DAYS_AFTER_ENROLLMENT => $this->daysAfter(
                $rule->days_after_enrollment,
                $enrollment?->enrolled_at ?? $enrollment?->created_at,
            ),

            DripReleaseType::DAYS_AFTER_PREVIOUS_COMPLETION => $this->daysAfterPreviousCompletion($rule, $enrollment),

            DripReleaseType::ON_COMPLETION => null,
        };
    }

    protected function daysAfter(?int $days, mixed $anchor): ?Carbon
    {
        if ($days === null || $anchor === null) {
            return null;
        }

        return Carbon::parse($anchor)->addDays($days);
    }

    protected function daysAfterPreviousCompletion(LmsDripRule $rule, ?LmsEnrollment $enrollment): ?Carbon
    {
        if ($enrollment === null || $rule->days_after_previous_completion === null) {
            return null;
        }

        $gate = $this->gatingItem($rule);

        if ($gate === null) {
            return null;
        }

        $completedAt = \App\Modules\LMS\Models\LmsActivityCompletion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('learning_item_id', $gate->id)
            ->whereNotNull('completed_at')
            ->value('completed_at');

        return $completedAt === null
            ? null
            : Carbon::parse($completedAt)->addDays($rule->days_after_previous_completion);
    }

    /* ---------------------------------------------------------------------
     | Penulisan aturan (§4) — dipakai controller pengelola course
     * ------------------------------------------------------------------- */

    public function upsert(LmsCourse $course, array $data, ?LmsActor $actor = null): LmsDripRule
    {
        $attributes = [
            'course_id' => $course->id,
            'section_id' => $data['section_id'] ?? null,
            'item_id' => $data['item_id'] ?? null,
            'activity_id' => $data['activity_id'] ?? null,
            'after_item_id' => $data['after_item_id'] ?? null,
            'release_type' => $data['release_type'] ?? DripReleaseType::IMMEDIATE->value,
            'release_at' => $data['release_at'] ?? null,
            'days_after_enrollment' => $data['days_after_enrollment'] ?? null,
            'days_after_previous_completion' => $data['days_after_previous_completion'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'note' => $data['note'] ?? null,
            'created_by' => $actor?->id,
        ];

        $rule = isset($data['id']) && $data['id'] !== null
            ? tap(LmsDripRule::query()->findOrFail($data['id']))->update($attributes)
            : LmsDripRule::query()->create($attributes);

        if ($course->drip_enabled !== true) {
            $course->forceFill(['drip_enabled' => true])->save();
        }

        $this->events->audit($course, 'drip_rule_saved', [
            'rule_id' => (int) $rule->id,
            'release_type' => $rule->release_type?->value,
        ], $actor);

        return $rule->fresh();
    }

    public function delete(LmsDripRule $rule, ?LmsActor $actor = null): void
    {
        $course = $rule->course;
        $rule->delete();

        if ($course !== null) {
            $this->events->audit($course, 'drip_rule_deleted', ['rule_id' => (int) $rule->id], $actor);
        }
    }

    /**
     * Ringkasan jadwal drip untuk UI guru.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(LmsCourse $course): array
    {
        return $this->rulesFor($course)->map(function (LmsDripRule $rule) {
            $label = $rule->item?->title
                ?? $rule->section?->title
                ?? $rule->activity?->title
                ?? 'Seluruh course';

            return [
                'id' => (int) $rule->id,
                'target' => ['type' => $rule->item_id !== null ? 'ITEM' : ($rule->section_id !== null ? 'SECTION' : ($rule->activity_id !== null ? 'ACTIVITY' : 'COURSE')), 'id' => (int) ($rule->item_id ?? $rule->section_id ?? $rule->activity_id ?? $rule->course_id), 'label' => $label],
                'release_type' => $rule->release_type?->value,
                'release_type_label' => $rule->release_type?->label(),
                'release_at' => $rule->release_at?->toIso8601String(),
                'days_after_enrollment' => $rule->days_after_enrollment,
                'days_after_previous_completion' => $rule->days_after_previous_completion,
                'after_item' => $rule->afterItem?->title,
                'is_active' => (bool) $rule->is_active,
                'note' => $rule->note,
                'description' => $this->describe($rule),
            ];
        })->all();
    }

    public function describe(LmsDripRule $rule): string
    {
        $target = $rule->item?->title ?? $rule->section?->title ?? 'Course';

        return match ($rule->release_type) {
            DripReleaseType::IMMEDIATE => $target.' tersedia langsung.',
            DripReleaseType::SPECIFIC_DATE => $target.' dibuka '.($rule->release_at?->translatedFormat('d F Y H:i') ?? '-').'.',
            DripReleaseType::DAYS_AFTER_ENROLLMENT => $target.' dibuka '.($rule->days_after_enrollment ?? 0).' hari setelah peserta mendaftar.',
            DripReleaseType::DAYS_AFTER_PREVIOUS_COMPLETION => $target.' dibuka '.($rule->days_after_previous_completion ?? 0).' hari setelah materi sebelumnya selesai.',
            DripReleaseType::ON_COMPLETION => $target.' dibuka setelah "'.($this->gatingItem($rule)?->title ?? 'materi sebelumnya').'" selesai.',
        };
    }
}
