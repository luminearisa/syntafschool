<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\ActivityStatus;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGroupMember;

/**
 * Ketersediaan aktivitas (§41) — satu pintu untuk menjawab "boleh dikerjakan?".
 *
 * Urutan pemeriksaan: status terbit → jendela waktu → prasyarat item (Phase 1)
 * → prasyarat aktivitas & nilai minimum → kelompok peserta. Setiap penolakan
 * membawa alasan siap tampil sehingga UI dapat menuliskan
 * "🔒 Terkunci — selesaikan Kuis 1 dengan nilai minimal 70%".
 */
class ActivityAvailabilityService
{
    public function __construct(
        private readonly CourseCompletionService $completions,
        private readonly ActivityCompletionService $activityCompletion,
        private readonly DripContentService $drip,
    ) {}

    public function evaluate(
        LmsActivity $activity,
        ?LmsActor $actor = null,
        ?LmsEnrollment $enrollment = null,
        bool $forStaff = false,
    ): AccessDecision {
        if ($forStaff) {
            return AccessDecision::allow();
        }

        if ($activity->status !== ActivityStatus::PUBLISHED) {
            return AccessDecision::deny('not_published', 'Aktivitas belum diterbitkan oleh guru.');
        }

        if ($enrollment === null) {
            return AccessDecision::deny('not_enrolled', 'Anda belum terdaftar pada course ini.');
        }

        if (! $enrollment->status?->grantsAccess()) {
            return AccessDecision::deny(
                'enrollment_'.$enrollment->status?->value,
                'Keikutsertaan Anda pada course ini sedang '.$enrollment->status?->label().'.',
            );
        }

        if ($activity->start_at !== null && $activity->start_at->isFuture()) {
            return AccessDecision::deny(
                'not_open_yet',
                'Aktivitas dibuka '.$activity->start_at->translatedFormat('d F Y H:i').'.',
            );
        }

        if ($activity->close_at !== null && $activity->close_at->isPast()) {
            return AccessDecision::deny(
                'closed',
                'Aktivitas sudah ditutup pada '.$activity->close_at->translatedFormat('d F Y H:i').'.',
            );
        }

        $item = $activity->learningItem;

        // Phase 4 (§4): pelepasan bertahap. Diperiksa sebelum prasyarat supaya
        // siswa melihat alasan "belum waktunya" alih-alih alasan turunan.
        if ($item !== null) {
            $course = $activity->course ?? $item->course;

            if ($course !== null) {
                $drip = $this->drip->evaluate($course, $enrollment, $item->section, $item, (int) $activity->id);

                if (! $drip->allowed) {
                    return $drip;
                }
            }
        }

        if ($item !== null) {
            $itemDecision = $this->completions->satisfiesPrerequisites($enrollment, $item);

            if (! $itemDecision->allowed) {
                return $itemDecision;
            }
        }

        $rules = (array) ($activity->availability ?? []);

        $requiredIds = array_values(array_filter(array_map('intval', (array) ($rules['required_activity_ids'] ?? []))));
        $requiredGrade = $rules['required_activity_grade'] ?? null;

        foreach ($requiredIds as $requiredId) {
            $required = LmsActivity::query()->find($requiredId);

            if ($required === null) {
                continue;
            }

            if (! $this->activityCompletion->isComplete($required, $enrollment)) {
                return AccessDecision::deny(
                    'required_activity_incomplete',
                    'Selesaikan "'.$required->title.'" terlebih dahulu.',
                    (int) $required->learning_item_id,
                );
            }

            if ($requiredGrade !== null) {
                $value = LmsActivityGrade::query()
                    ->where('activity_id', $requiredId)
                    ->where('user_id', $enrollment->user_id)
                    ->first()?->percent();

                if ($value === null || $value < (float) $requiredGrade) {
                    return AccessDecision::deny(
                        'required_grade_not_met',
                        '"'.$required->title.'" harus mencapai nilai minimal '.$requiredGrade.'%.',
                    );
                }
            }
        }

        $groupIds = array_values(array_filter(array_map('intval', (array) ($rules['group_ids'] ?? []))));

        if ($groupIds !== []) {
            $isMember = LmsGroupMember::query()
                ->whereIn('group_id', $groupIds)
                ->where('enrollment_id', $enrollment->id)
                ->exists();

            if (! $isMember) {
                return AccessDecision::deny('group_restricted', 'Aktivitas ini hanya untuk kelompok peserta tertentu.');
            }
        }

        return AccessDecision::allow();
    }

    /**
     * Status ringkas untuk daftar aktivitas siswa (§42).
     *
     * @return array<string, mixed>
     */
    public function studentState(LmsActivity $activity, ?LmsEnrollment $enrollment, bool $forStaff = false): array
    {
        $decision = $this->evaluate($activity, null, $enrollment, $forStaff);
        $completed = $enrollment !== null && $this->activityCompletion->isComplete($activity, $enrollment);

        return [
            'locked' => ! $decision->allowed,
            'reason' => $decision->allowed ? null : $decision->reason,
            'reason_label' => $decision->allowed ? null : $decision->message,
            'completed' => $completed,
            'past_due' => $activity->isPastDue(),
            'closed' => $activity->close_at !== null && $activity->close_at->isPast(),
            'not_open_yet' => $activity->start_at !== null && $activity->start_at->isFuture(),
            'opens_at' => $activity->start_at?->toIso8601String(),
            'due_at' => $activity->due_at?->toIso8601String(),
            'close_at' => $activity->close_at?->toIso8601String(),
        ];
    }

    /**
     * Aktivitas tetangga pada course yang sama — navigasi Previous/Next (§42).
     *
     * @return array{previous: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    public function neighbours(LmsActivity $activity): array
    {
        $siblings = LmsActivity::query()
            ->where('course_id', $activity->course_id)
            ->published()
            ->with('learningItem')
            ->get()
            ->sortBy(fn (LmsActivity $item) => sprintf(
                '%05d-%05d-%05d',
                (int) ($item->learningItem?->section_id ?? 0),
                (int) ($item->learningItem?->sort_order ?? 0),
                (int) $item->id,
            ))
            ->values();

        $index = $siblings->search(fn (LmsActivity $item) => (int) $item->id === (int) $activity->id);

        if ($index === false) {
            return ['previous' => null, 'next' => null];
        }

        $previous = $siblings->get($index - 1);
        $next = $siblings->get($index + 1);

        return [
            'previous' => $previous === null ? null : $this->neighbourPayload($previous),
            'next' => $next === null ? null : $this->neighbourPayload($next),
        ];
    }

    /** @return array<string, mixed> */
    protected function neighbourPayload(LmsActivity $activity): array
    {
        return [
            'id' => (int) $activity->id,
            'title' => (string) $activity->title,
            'activity_type' => $activity->activity_type?->value,
            'type_label' => $activity->activity_type?->label(),
        ];
    }

    /** Tipe aktivitas yang punya jendela pengerjaan sendiri (kuis/tugas). */
    public function hasOwnWindow(ActivityType $type): bool
    {
        return in_array($type, [ActivityType::QUIZ, ActivityType::ASSIGNMENT], true);
    }

    /** Aturan penyelesaian efektif (item → aktivitas). */
    public function completionType(LmsActivity $activity): CompletionType
    {
        return $activity->completion_rule
            ?? $activity->learningItem?->completion_type
            ?? CompletionType::SUBMIT;
    }
}
