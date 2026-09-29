<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActivityStatus;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aktivitas pembelajaran Phase 2 (§2, §3, §43–45).
 *
 * Semua aktivitas tetap berupa satu baris `lms_learning_items` sehingga
 * mengikuti course, section, urutan, prasyarat, visibilitas, dan mesin
 * penyelesaian Phase 1. `lms_activities` menyimpan atribut umum aktivitas,
 * sedangkan tabel per tipe (`lms_assignments`, `lms_quizzes`, …) menyimpan
 * konfigurasi khusus.
 */
class ActivityService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly CourseContentService $content,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /** Mapping tipe aktivitas → konfiguratornya (§1). */
    public function typeService(ActivityType $type): ActivityConfiguratorInterface
    {
        return match ($type) {
            ActivityType::ASSIGNMENT => app(AssignmentService::class),
            ActivityType::QUIZ => app(QuizService::class),
            ActivityType::FORUM => app(ForumService::class),
            ActivityType::LESSON => app(LessonService::class),
            ActivityType::CHOICE => app(ChoiceService::class),
            ActivityType::SURVEY => app(SurveyService::class),
            ActivityType::SCORM => app(ScormActivityService::class),
            default => throw ValidationException::withMessages([
                'activity_type' => 'Tipe aktivitas '.$type->value.' belum tersedia pada Phase 2.',
            ]),
        };
    }

    /**
     * Buat aktivitas lengkap: learning item + baris aktivitas + konfigurasi tipe.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(LmsCourseSection $section, array $data, LmsActor $actor): LmsActivity
    {
        $type = ActivityType::tryFrom((string) ($data['activity_type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages(['activity_type' => 'Tipe aktivitas tidak dikenal.']);
        }

        $this->typeService($type); // pastikan tipenya tersedia sebelum menyentuh database

        return DB::transaction(function () use ($section, $data, $actor, $type): LmsActivity {
            $item = $this->content->createItem($section, [
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'item_type' => ItemType::ACTIVITY->value,
                'activity_type' => $type->value,
                'visibility' => $data['visibility'] ?? 'VISIBLE',
                'is_required' => $data['is_required'] ?? true,
                'completion_type' => $data['completion_type'] ?? $this->defaultCompletion($type),
                'completion_min_grade' => $data['completion_min_grade'] ?? null,
                'is_preview' => $data['is_preview'] ?? false,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'availability' => $data['availability'] ?? null,
                'require_previous_item' => $data['require_previous_item'] ?? null,
                'required_item_ids' => $data['required_item_ids'] ?? null,
                'required_grade' => $data['required_grade'] ?? null,
            ], $actor);

            $activity = $this->createForItem($item, $data, $actor, $type);

            $this->audit('LMS_ACTIVITY_CREATED', $activity, [
                'activity_type' => $type->value,
                'course_id' => $activity->course_id,
            ]);

            return $activity->fresh(['assignment', 'quiz', 'forum', 'lesson', 'choice', 'survey']);
        });
    }

    /**
     * Buat baris aktivitas untuk learning item yang sudah ada (dipakai juga oleh
     * duplikasi & pengubahan tipe item).
     *
     * @param  array<string, mixed>  $data
     */
    public function createForItem(LmsLearningItem $item, array $data, LmsActor $actor, ?ActivityType $type = null): LmsActivity
    {
        $type ??= $item->activity_type ?? ActivityType::ASSIGNMENT;

        $activity = LmsActivity::create([
            'learning_item_id' => $item->id,
            'course_id' => $item->course_id,
            'section_id' => $item->section_id,
            'activity_type' => $type->value,
            'title' => $data['title'] ?? $item->title,
            'description' => $this->sanitizer->clean($data['description'] ?? $item->description),
            'instructions' => $this->sanitizer->clean($data['instructions'] ?? null),
            'status' => $data['status'] ?? ActivityStatus::DRAFT->value,
            'start_at' => $data['start_at'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'close_at' => $data['close_at'] ?? null,
            'visibility' => $data['visibility'] ?? 'VISIBLE',
            'completion_rule' => $data['completion_rule'] ?? $this->defaultCompletion($type),
            'completion_min_grade' => $data['completion_min_grade'] ?? $item->completion_min_grade,
            'availability' => $data['availability'] ?? $item->availability,
            'settings' => $data['settings'] ?? null,
            'is_graded' => $data['is_graded'] ?? $type->isGraded(),
            'created_by' => $actor->id,
        ]);

        $this->typeService($type)->createForActivity($activity, $data);

        return $activity;
    }

    /** @param array<string, mixed> $data */
    public function update(LmsActivity $activity, array $data, LmsActor $actor): LmsActivity
    {
        foreach (['title', 'description', 'instructions'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $field === 'title'
                    ? $data[$field]
                    : $this->sanitizer->clean($data[$field]);
            }
        }

        $activity->fill(collect($data)->only([
            'title', 'description', 'instructions', 'start_at', 'due_at', 'close_at',
            'visibility', 'completion_rule', 'completion_min_grade', 'availability',
            'settings', 'is_graded',
        ])->all());

        $activity->updated_by = $actor->id;
        $activity->save();

        // Item tetap sumber kebenaran untuk judul, visibilitas, prasyarat & aturan selesai.
        $item = $activity->learningItem;
        if ($item !== null) {
            $this->content->updateItem($item, collect($data)->only([
                'title', 'description', 'visibility', 'is_required', 'completion_type',
                'completion_min_grade', 'is_preview', 'estimated_minutes',
                'availability', 'require_previous_item', 'required_item_ids', 'required_grade',
            ])->all());
        }

        $this->typeService($activity->activity_type)->updateForActivity($activity, $data);

        $this->audit('LMS_ACTIVITY_UPDATED', $activity, ['fields' => array_keys($data)]);

        return $activity->fresh();
    }

    public function publish(LmsActivity $activity, LmsActor $actor): LmsActivity
    {
        $this->assertPublishable($activity);

        $activity->forceFill([
            'status' => ActivityStatus::PUBLISHED->value,
            'published_at' => $activity->published_at ?? now(),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit('LMS_ACTIVITY_PUBLISHED', $activity, ['course_id' => $activity->course_id]);

        return $activity->fresh();
    }

    public function unpublish(LmsActivity $activity, LmsActor $actor): LmsActivity
    {
        $activity->forceFill([
            'status' => ActivityStatus::DRAFT->value,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit('LMS_ACTIVITY_UNPUBLISHED', $activity);

        return $activity->fresh();
    }

    public function archive(LmsActivity $activity, LmsActor $actor): LmsActivity
    {
        $activity->forceFill([
            'status' => ActivityStatus::ARCHIVED->value,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit('LMS_ACTIVITY_ARCHIVED', $activity);

        return $activity->fresh();
    }

    public function delete(LmsActivity $activity): void
    {
        $item = $activity->learningItem;
        $activity->delete();

        // Menghapus aktivitas berarti menghapus item-nya dari silabus.
        if ($item !== null) {
            $this->content->deleteItem($item);
        }
    }

    /**
     * Duplikasi aktivitas (§44): konfigurasi & soal disalin, riwayat tidak.
     */
    public function duplicate(LmsActivity $activity, LmsActor $actor, ?string $title = null): LmsActivity
    {
        return DB::transaction(function () use ($activity, $actor, $title): LmsActivity {
            $item = $activity->learningItem;

            if ($item === null) {
                throw ValidationException::withMessages(['activity' => 'Aktivitas tidak memiliki learning item.']);
            }

            $copyItem = $this->content->duplicateItem($item);
            $copyItem->forceFill(['title' => $title ?? $activity->title.' (salinan)'])->save();

            $copy = LmsActivity::create([
                'learning_item_id' => $copyItem->id,
                'course_id' => $activity->course_id,
                'section_id' => $activity->section_id,
                'activity_type' => $activity->activity_type->value,
                'title' => $title ?? $activity->title.' (salinan)',
                'description' => $activity->description,
                'instructions' => $activity->instructions,
                'status' => ActivityStatus::DRAFT->value,
                'start_at' => $activity->start_at,
                'due_at' => $activity->due_at,
                'close_at' => $activity->close_at,
                'visibility' => $activity->visibility?->value,
                'completion_rule' => $activity->completion_rule?->value,
                'completion_min_grade' => $activity->completion_min_grade,
                'availability' => $activity->availability,
                'settings' => $activity->settings,
                'is_graded' => $activity->is_graded,
                'created_by' => $actor->id,
            ]);

            $this->typeService($activity->activity_type)->duplicateForActivity($activity, $copy);

            $this->audit('LMS_ACTIVITY_DUPLICATED', $copy, ['source_activity_id' => $activity->id]);
            $this->audit('LMS_ACTIVITY_DUPLICATED', $activity, ['copy_activity_id' => $copy->id]);

            return $copy->fresh();
        });
    }

    // ------------------------------------------------------------------ baca

    /**
     * Daftar aktivitas sebuah course.
     *
     * @param  array<string, mixed>  $filters
     */
    public function forCourse(LmsCourse $course, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return LmsActivity::query()
            ->where('course_id', $course->id)
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('activity_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['section_id'] ?? null, fn (Builder $q, $section) => $q->where('section_id', $section))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->with(['assignment', 'quiz', 'forum', 'lesson', 'choice', 'survey', 'learningItem'])
            ->orderByRaw('due_at is null, due_at asc')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /** Semua aktivitas (untuk dasbor guru/admin). */
    public function all(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return LmsActivity::query()
            ->when($filters['course_ids'] ?? null, fn (Builder $q, $ids) => $q->whereIn('course_id', (array) $ids))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('activity_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $q, $term) => $q->where('title', 'like', '%'.$term.'%'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?LmsActivity
    {
        return LmsActivity::query()
            ->with(['assignment', 'quiz', 'forum', 'lesson', 'choice', 'survey', 'course', 'learningItem'])
            ->find($id);
    }

    public function findByLearningItem(LmsLearningItem $item): ?LmsActivity
    {
        return LmsActivity::query()->where('learning_item_id', $item->id)->first();
    }

    /**
     * Aktivitas yang akan datang / terlambat untuk seorang peserta (§49).
     *
     * @return Collection<int, LmsActivity>
     */
    public function upcomingForUser(int $userId, int $limit = 5): Collection
    {
        $courseIds = $this->activeCourseIds($userId);

        if ($courseIds === []) {
            return new Collection();
        }

        return LmsActivity::query()
            ->whereIn('course_id', $courseIds)
            ->published()
            ->where(function (Builder $q): void {
                $q->whereNull('due_at')->orWhere('due_at', '>=', now()->subDays(14));
            })
            ->with(['assignment', 'quiz', 'course'])
            ->orderByRaw('due_at is null, due_at asc')
            ->limit($limit)
            ->get();
    }

    /** @return list<int> */
    public function activeCourseIds(int $userId): array
    {
        return array_map('intval', \App\Modules\LMS\Models\LmsEnrollment::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->pluck('course_id')
            ->unique()
            ->all());
    }

    // ------------------------------------------------------------------ bantu

    /** §45 — guru dapat melihat tampilan siswa tanpa membuat enrollment palsu. */
    public function previewPayload(LmsActivity $activity): array
    {
        $exported = $this->payload($activity);

        $exported['preview'] = true;
        $exported['notice'] = 'Mode pratinjau guru: tampilan ini tidak mencatat pengumpulan, nilai, atau penyelesaian.';

        return $exported;
    }

    /** Payload umum aktivitas (dipakai siswa, guru, dan wali). */
    public function payload(LmsActivity $activity): array
    {
        $item = $activity->learningItem;

        return [
            'id' => (int) $activity->id,
            'course_id' => (int) $activity->course_id,
            'section_id' => $activity->section_id === null ? null : (int) $activity->section_id,
            'learning_item_id' => (int) $activity->learning_item_id,
            'activity_type' => $activity->activity_type?->value,
            'title' => (string) $activity->title,
            'description' => $activity->description,
            'instructions' => $activity->instructions,
            'status' => $activity->status?->value,
            'start_at' => $activity->start_at?->toIso8601String(),
            'due_at' => $activity->due_at?->toIso8601String(),
            'close_at' => $activity->close_at?->toIso8601String(),
            'visibility' => $activity->visibility?->value,
            'completion_rule' => $activity->completion_rule?->value,
            'completion_min_grade' => $activity->completion_min_grade,
            'availability' => $activity->availability,
            'is_graded' => (bool) $activity->is_graded,
            'is_required' => (bool) ($item?->is_required ?? true),
            'estimated_minutes' => $item?->estimated_minutes,
            'player_url' => $activity->playerUrl(),
        ];
    }

    protected function defaultCompletion(ActivityType $type): string
    {
        return match ($type) {
            ActivityType::LESSON => CompletionType::COMPLETE_ACTIVITY->value,
            ActivityType::CHOICE, ActivityType::SURVEY => CompletionType::SUBMIT->value,
            ActivityType::FORUM => CompletionType::SUBMIT->value,
            default => CompletionType::SUBMIT->value,
        };
    }

    /** Aktivitas tanpa konten wajib tidak boleh terbit (§3). */
    protected function assertPublishable(LmsActivity $activity): void
    {
        $errors = [];

        if (trim((string) $activity->title) === '') {
            $errors['title'] = 'Judul aktivitas wajib diisi.';
        }

        $type = $activity->activity_type;
        $detail = $activity->detail();

        if ($type === ActivityType::QUIZ && $detail !== null && $detail->questions()->count() === 0) {
            $errors['questions'] = 'Kuis harus memiliki minimal satu soal sebelum diterbitkan.';
        }

        if ($type === ActivityType::LESSON && $detail !== null && $detail->pages()->count() === 0) {
            $errors['pages'] = 'Pelajaran harus memiliki minimal satu halaman sebelum diterbitkan.';
        }

        if ($type === ActivityType::CHOICE && $detail !== null && $detail->options()->count() < 2) {
            $errors['options'] = 'Polling membutuhkan minimal dua pilihan.';
        }

        if ($type === ActivityType::SURVEY && $detail !== null && $detail->questions()->count() === 0) {
            $errors['questions'] = 'Survei harus memiliki minimal satu pertanyaan sebelum diterbitkan.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
