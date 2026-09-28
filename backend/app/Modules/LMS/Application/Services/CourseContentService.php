<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Enums\ResourceType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsBookChapter;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Penyusunan struktur course: Section → Learning Item → Resource/Bab (§10–§14).
 *
 * Semua perubahan struktur menandai progress perlu dihitung ulang: menambah
 * item baru menurunkan persentase peserta, dan itu memang benar.
 */
class CourseContentService
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly CourseProgressService $progress,
        private readonly CourseCompletionService $completions,
    ) {}

    // --------------------------------------------------------------------
    // Section
    // --------------------------------------------------------------------

    public function createSection(LmsCourse $course, array $data): LmsCourseSection
    {
        return $course->sections()->create([
            'title' => $data['title'],
            'description' => $this->sanitizer->clean($data['description'] ?? null),
            'summary' => $data['summary'] ?? null,
            'visibility' => $data['visibility'] ?? ContentVisibility::VISIBLE->value,
            'available_from' => $data['available_from'] ?? null,
            'available_until' => $data['available_until'] ?? null,
            'availability' => $data['availability'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) $course->sections()->max('sort_order') + 1),
        ]);
    }

    public function updateSection(LmsCourseSection $section, array $data): LmsCourseSection
    {
        if (array_key_exists('description', $data)) {
            $data['description'] = $this->sanitizer->clean($data['description']);
        }

        $section->fill($data)->save();
        $this->progress->recomputeForCourse($section->course);

        return $section;
    }

    public function deleteSection(LmsCourseSection $section): void
    {
        $course = $section->course;
        $section->delete();

        if ($course !== null) {
            $this->progress->recomputeForCourse($course);
        }
    }

    public function duplicateSection(LmsCourseSection $section): LmsCourseSection
    {
        $copy = $section->replicate(['created_at', 'updated_at']);
        $copy->title = $section->title.' (salinan)';
        $copy->sort_order = (int) $section->sort_order + 1;
        $copy->save();

        foreach ($section->items as $item) {
            $this->duplicateItem($item, $copy);
        }

        $this->progress->recomputeForCourse($section->course);

        return $copy;
    }

    /** @param list<int> $ids */
    public function reorderSections(LmsCourse $course, array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            LmsCourseSection::query()
                ->where('course_id', $course->id)
                ->whereKey($id)
                ->update(['sort_order' => $index + 1]);
        }
    }

    // --------------------------------------------------------------------
    // Learning item
    // --------------------------------------------------------------------

    public function createItem(LmsCourseSection $section, array $data, LmsActor $actor): LmsLearningItem
    {
        $itemType = ItemType::from($data['item_type'] ?? ItemType::RESOURCE->value);
        $resourceType = $itemType === ItemType::RESOURCE
            ? ResourceType::from($data['resource_type'] ?? ResourceType::PAGE->value)
            : null;
        $activityType = $itemType === ItemType::ACTIVITY
            ? ActivityType::from($data['activity_type'] ?? ActivityType::ASSIGNMENT->value)
            : null;

        $item = $section->items()->create([
            'course_id' => $section->course_id,
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'description' => $this->sanitizer->clean($data['description'] ?? null),
            'item_type' => $itemType->value,
            'resource_type' => $resourceType?->value,
            'activity_type' => $activityType?->value,
            'visibility' => $data['visibility'] ?? ContentVisibility::VISIBLE->value,
            'is_required' => $data['is_required'] ?? true,
            'completion_type' => $data['completion_type'] ?? $this->defaultCompletion($itemType, $resourceType, $activityType),
            'completion_min_grade' => $data['completion_min_grade'] ?? null,
            'is_preview' => $data['is_preview'] ?? false,
            'availability' => $this->normalizeAvailability($data),
            'estimated_minutes' => $data['estimated_minutes'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) $section->items()->max('sort_order') + 1),
            'created_by' => $actor->id,
        ]);

        $this->saveResource($item, $data);
        $this->progress->recomputeForCourse($section->course);

        return $item->fresh(['resource', 'chapters']);
    }

    public function updateItem(LmsLearningItem $item, array $data): LmsLearningItem
    {
        if (array_key_exists('description', $data)) {
            $data['description'] = $this->sanitizer->clean($data['description']);
        }

        if (array_key_exists('resource_type', $data) && $data['resource_type'] !== null) {
            $item->resource_type = $data['resource_type'];
        }

        if (array_key_exists('activity_type', $data) && $data['activity_type'] !== null) {
            $item->activity_type = $data['activity_type'];
        }

        foreach (['title', 'visibility', 'is_required', 'completion_type', 'completion_min_grade', 'is_preview', 'estimated_minutes'] as $field) {
            if (array_key_exists($field, $data)) {
                $item->{$field} = $data[$field];
            }
        }

        if (array_key_exists('availability', $data) || array_key_exists('require_previous_item', $data)) {
            $item->availability = $this->normalizeAvailability($data);
        }

        $item->save();
        $this->saveResource($item, $data);
        $this->progress->recomputeForCourse($item->course);

        return $item->fresh(['resource', 'chapters']);
    }

    public function deleteItem(LmsLearningItem $item): void
    {
        $course = $item->course;
        $item->delete();

        if ($course !== null) {
            $this->progress->recomputeForCourse($course);
        }
    }

    public function duplicateItem(LmsLearningItem $item, ?LmsCourseSection $targetSection = null): LmsLearningItem
    {
        $copy = $item->replicate(['created_at', 'updated_at']);
        $copy->section_id = $targetSection?->id ?? $item->section_id;
        $copy->title = $item->title.' (salinan)';
        $copy->sort_order = (int) $item->sort_order + 1;
        $copy->save();

        if ($item->resource !== null) {
            $resource = $item->resource->replicate(['learning_item_id', 'created_at', 'updated_at']);
            $resource->learning_item_id = $copy->id;
            $resource->save();
        }

        foreach ($item->chapters as $chapter) {
            $newChapter = $chapter->replicate(['learning_item_id', 'created_at', 'updated_at']);
            $newChapter->learning_item_id = $copy->id;
            $newChapter->save();
        }

        return $copy;
    }

    public function moveItem(LmsLearningItem $item, LmsCourseSection $section): LmsLearningItem
    {
        if ((int) $section->course_id !== (int) $item->course_id) {
            throw ValidationException::withMessages([
                'section_id' => 'Section tujuan berada pada course yang berbeda.',
            ]);
        }

        $item->forceFill([
            'section_id' => $section->id,
            'sort_order' => (int) $section->items()->max('sort_order') + 1,
        ])->save();

        $this->progress->recomputeForCourse($item->course);

        return $item;
    }

    /** @param list<int> $ids */
    public function reorderItems(LmsCourseSection $section, array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            LmsLearningItem::query()
                ->where('section_id', $section->id)
                ->whereKey($id)
                ->update(['sort_order' => $index + 1]);
        }
    }

    // --------------------------------------------------------------------
    // Resource
    // --------------------------------------------------------------------

    public function saveResource(LmsLearningItem $item, array $data): ?LmsResource
    {
        if ($item->item_type !== ItemType::RESOURCE) {
            return null;
        }

        $resource = $item->resource ?? new LmsResource(['learning_item_id' => $item->id]);
        $type = $item->resource_type;

        switch ($type) {
            case ResourceType::PAGE:
            case ResourceType::TEXT:
                $resource->content = $this->sanitizer->clean($data['content'] ?? $resource->content);
                break;

            case ResourceType::URL:
                $resource->external_url = $data['external_url'] ?? $resource->external_url;
                $resource->content = $this->sanitizer->clean($data['content'] ?? null);
                $resource->open_in = $data['open_in'] ?? $resource->open_in ?? 'NEW_TAB';
                $resource->media_source = 'EXTERNAL';
                break;

            case ResourceType::VIDEO:
            case ResourceType::AUDIO:
                $resource->content = $this->sanitizer->clean($data['content'] ?? null);
                $resource->external_url = $data['external_url'] ?? $resource->external_url;
                $resource->video_provider = $data['video_provider'] ?? $this->guessProvider($resource->external_url);
                $resource->media_source = match (true) {
                    $resource->file_path !== null => 'UPLOAD',
                    ($data['embed_html'] ?? null) !== null => 'EMBED',
                    default => 'EXTERNAL',
                };
                $resource->duration_seconds = $data['duration_seconds'] ?? $resource->duration_seconds;
                $resource->open_in = $data['open_in'] ?? 'EMBED';
                break;

            case ResourceType::BOOK:
                $resource->content = $this->sanitizer->clean($data['content'] ?? null);
                break;

            case ResourceType::FILE:
            case ResourceType::FOLDER:
                $resource->file_path = $data['file_path'] ?? $resource->file_path;
                $resource->file_name = $data['file_name'] ?? $resource->file_name;
                $resource->file_mime = $data['file_mime'] ?? $resource->file_mime;
                $resource->file_size = $data['file_size'] ?? $resource->file_size;
                $resource->content = $this->sanitizer->clean($data['content'] ?? null);
                break;
        }

        $resource->save();

        if ($type === ResourceType::BOOK && array_key_exists('chapters', $data)) {
            $this->saveChapters($item, (array) $data['chapters']);
        }

        return $resource;
    }

    /** Simpan berkas unggahan pada disk Laravel (bukan database) (§13, §34). */
    public function storeUpload(LmsLearningItem $item, UploadedFile $file): LmsResource
    {
        $disk = (string) config('lms.uploads.disk', 'local');
        $directory = trim((string) config('lms.uploads.directory', 'lms'), '/').'/'.$item->course_id;

        $path = $file->store($directory, $disk);

        $resource = $item->resource ?? new LmsResource(['learning_item_id' => $item->id]);
        $resource->fill([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'media_source' => 'UPLOAD',
        ]);

        if ($item->resource_type === ResourceType::VIDEO || $item->resource_type === ResourceType::AUDIO) {
            $resource->open_in = 'EMBED';
        }

        $resource->save();

        return $resource;
    }

    // --------------------------------------------------------------------
    // Book
    // --------------------------------------------------------------------

    /** @param list<array<string, mixed>> $chapters */
    public function saveChapters(LmsLearningItem $item, array $chapters): void
    {
        $keptIds = [];

        foreach (array_values($chapters) as $index => $chapter) {
            $attributes = [
                'title' => $chapter['title'] ?? 'Bab '.($index + 1),
                'content' => $this->sanitizer->clean($chapter['content'] ?? null),
                'sort_order' => $index + 1,
            ];

            $id = $chapter['id'] ?? null;

            if ($id !== null && (int) $id > 0) {
                $model = LmsBookChapter::query()->where('learning_item_id', $item->id)->whereKey($id)->first();

                if ($model !== null) {
                    $model->fill($attributes)->save();
                    $keptIds[] = $model->id;

                    continue;
                }
            }

            $created = $item->chapters()->create($attributes);
            $keptIds[] = $created->id;
        }

        $item->chapters()->whereNotIn('id', $keptIds)->delete();
    }

    // --------------------------------------------------------------------
    // Struktur untuk UI
    // --------------------------------------------------------------------

    /**
     * Item sebelum/sesudah dalam urutan belajar — bahan tombol Previous/Next
     * pada pengalaman belajar (§39).
     *
     * @return array<string, mixed>|null
     */
    public function navigation(LmsLearningItem $item, string $direction = 'next'): ?array
    {
        $items = $this->completions->orderedItems((int) $item->course_id);
        $index = $items->search(fn (LmsLearningItem $candidate) => (int) $candidate->id === (int) $item->id);

        if ($index === false) {
            return null;
        }

        $target = $direction === 'previous' ? $items->get($index - 1) : $items->get($index + 1);

        if ($target === null) {
            return null;
        }

        return [
            'id' => (int) $target->id,
            'title' => (string) $target->title,
            'section_id' => (int) $target->section_id,
            'section_title' => $target->section?->title,
        ];
    }

    /**
     * Pohon course lengkap dengan status akses & penyelesaian per item.
     * Dipakai halaman belajar (player), halaman course, dan pratinjau guru.
     *
     * @return list<array<string, mixed>>
     */
    public function structure(LmsCourse $course, ?LmsActor $actor, ?LmsEnrollment $enrollment, CourseAccessService $access): array
    {
        $completedIds = $enrollment !== null
            ? $this->completions->completedItemIds($enrollment)
            : [];

        $sections = $course->sections()->with(['items' => fn ($q) => $q->with(['resource:id,learning_item_id,file_name,file_path,external_url,open_in,video_provider,duration_seconds', 'chapters:id,learning_item_id,title,sort_order'])->orderBy('sort_order')])->get();

        // Peta learning item → aktivitas LMS, agar silabus dapat menautkan
        // langsung ke halaman pengerjaan aktivitas (Phase 2).
        $activityIds = \App\Modules\LMS\Models\LmsActivity::query()
            ->where('course_id', $course->id)
            ->pluck('id', 'learning_item_id')
            ->all();

        $output = [];

        foreach ($sections as $section) {
            $sectionDecision = $access->decide($course, $actor, null, $section);
            $items = [];

            foreach ($section->items as $item) {
                $decision = $access->decide($course, $actor, $item, $section);

                $items[] = [
                    'id' => (int) $item->id,
                    'section_id' => (int) $item->section_id,
                    'title' => (string) $item->title,
                    'description' => $item->description,
                    'item_type' => $item->item_type?->value,
                    'resource_type' => $item->resource_type?->value,
                    'activity_type' => $item->activity_type?->value,
                    'activity_id' => isset($activityIds[$item->id]) ? (int) $activityIds[$item->id] : null,
                    'icon' => $item->resource_type?->icon() ?? $item->activity_type?->icon() ?? 'file',
                    'is_required' => (bool) $item->is_required,
                    'completion_type' => $item->completion_type?->value,
                    'is_preview' => (bool) $item->is_preview,
                    'estimated_minutes' => $item->estimated_minutes,
                    'sort_order' => (int) $item->sort_order,
                    'completed' => in_array((int) $item->id, $completedIds, true),
                    'locked' => ! $decision->allowed,
                    'lock_reason' => $decision->allowed ? null : $decision->message,
                    'availability' => $item->availabilityRules(),
                ];
            }

            $output[] = [
                'id' => (int) $section->id,
                'title' => (string) $section->title,
                'description' => $section->description,
                'summary' => $section->summary,
                'sort_order' => (int) $section->sort_order,
                'visibility' => $section->visibility?->value,
                'available_from' => $section->available_from?->toIso8601String(),
                'available_until' => $section->available_until?->toIso8601String(),
                'locked' => ! $sectionDecision->allowed,
                'lock_reason' => $sectionDecision->allowed ? null : $sectionDecision->message,
                'items' => $items,
            ];
        }

        return $output;
    }

    // --------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------

    protected function defaultCompletion(ItemType $itemType, ?ResourceType $resourceType, ?ActivityType $activityType): string
    {
        if ($itemType === ItemType::RESOURCE) {
            return match ($resourceType) {
                ResourceType::PAGE, ResourceType::BOOK => CompletionType::VIEW->value,
                ResourceType::VIDEO, ResourceType::AUDIO => CompletionType::VIEW->value,
                ResourceType::FILE, ResourceType::URL => CompletionType::VIEW->value,
                ResourceType::FOLDER => CompletionType::NONE->value,
                default => CompletionType::MANUAL->value,
            };
        }

        return match ($activityType) {
            ActivityType::ASSIGNMENT, ActivityType::WORKSHOP => CompletionType::SUBMIT->value,
            ActivityType::QUIZ => CompletionType::PASS->value,
            ActivityType::FORUM, ActivityType::SURVEY, ActivityType::CHOICE => CompletionType::COMPLETE_ACTIVITY->value,
            ActivityType::LESSON => CompletionType::VIEW->value,
            default => CompletionType::MANUAL->value,
        };
    }

    /** Aturan ketersediaan disimpan sebagai satu objek JSON di kolom `availability`. */
    protected function normalizeAvailability(array $data): ?array
    {
        $existing = $data['availability'] ?? [];

        $rules = array_filter([
            'available_from' => $data['available_from'] ?? ($existing['available_from'] ?? null),
            'available_until' => $data['available_until'] ?? ($existing['available_until'] ?? null),
            'require_previous_item' => $data['require_previous_item'] ?? ($existing['require_previous_item'] ?? null),
            'required_item_ids' => $data['required_item_ids'] ?? ($existing['required_item_ids'] ?? null),
            'required_section_ids' => $data['required_section_ids'] ?? ($existing['required_section_ids'] ?? null),
            'required_grade' => $data['required_grade'] ?? ($existing['required_grade'] ?? null),
            'required_completion' => $data['required_completion'] ?? ($existing['required_completion'] ?? null),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $rules === [] ? null : $rules;
    }

    protected function guessProvider(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'youtu') => 'youtube',
            str_contains($host, 'vimeo') => 'vimeo',
            str_contains($host, 'drive.google') => 'google-drive',
            $host !== '' => 'external',
            default => null,
        };
    }

    /** Path unduhan aman untuk resource FILE (dipakai controller unduhan). */
    public function downloadPath(LmsResource $resource): string
    {
        if ($resource->file_path === null || ! Storage::disk((string) config('lms.uploads.disk'))->exists($resource->file_path)) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak ditemukan pada penyimpanan.',
            ]);
        }

        return $resource->file_path;
    }

    public function slugFrom(string $title): string
    {
        return Str::slug($title) ?: 'lms-'.Str::random(6);
    }
}
