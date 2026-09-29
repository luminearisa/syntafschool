<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseTemplate;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Facades\DB;

/**
 * Template course (§47–§48).
 *
 * Template menyimpan KERANGKA: section, item, dan isi materi — tanpa data siswa,
 * tanpa submission, tanpa nilai. Guru dapat memakainya untuk course baru atau
 * menambahkannya ke course yang sedang berjalan, sehingga tidak ada lagi
 * menyusun ulang struktur yang sama dari nol setiap semester.
 *
 * Payload disimpan sebagai JSON (`lms_course_templates.payload`) dengan versi,
 * supaya format lama tetap dapat dibaca setelah format baru ditambahkan.
 */
class CourseTemplateService
{
    use LogsLmsAudit;

    public const PAYLOAD_VERSION = 1;

    public function __construct(
        private readonly CourseContentService $content,
        private readonly ActivityService $activities,
    ) {}

    /** @return list<array<string, mixed>> */
    public function templates(array $filters = []): array
    {
        return LmsCourseTemplate::query()
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['search'] ?? null) !== null, fn ($query) => $query->where('title', 'like', '%'.$filters['search'].'%'))
            ->orderByDesc('is_system')
            ->orderBy('title')
            ->get()
            ->map(fn (LmsCourseTemplate $template) => $this->row($template))
            ->all();
    }

    public function find(int $id): LmsCourseTemplate
    {
        $template = LmsCourseTemplate::query()->find($id);

        if ($template === null) {
            LmsFault::notFound('Template course tidak ditemukan.');
        }

        return $template;
    }

    /**
     * Simpan struktur sebuah course sebagai template (§47).
     *
     * @param  array<string, mixed>  $options  include_content, include_files, include_activities
     */
    public function createFromCourse(LmsCourse $course, array $data, LmsActor $actor, array $options = []): LmsCourseTemplate
    {
        $payload = $this->snapshotCourse($course, $options);

        $template = LmsCourseTemplate::query()->create([
            'title' => $data['title'] ?? $course->name.' (template)',
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? $course->category_id,
            'source_course_id' => $course->id,
            'payload' => $payload,
            'sections_count' => count($payload['sections']),
            'items_count' => array_sum(array_map(fn (array $section) => count($section['items']), $payload['sections'])),
            'status' => $data['status'] ?? 'ACTIVE',
            'is_system' => false,
            'created_by' => $actor->id,
        ]);

        $this->audit('LMS_COURSE_TEMPLATE_CREATED', $template, [
            'source_course_id' => (int) $course->id,
            'sections' => $template->sections_count,
        ]);

        return $template;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, LmsActor $actor): LmsCourseTemplate
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            LmsFault::invalid('Judul template wajib diisi.', 'title');
        }

        $payload = (array) ($data['payload'] ?? ['version' => self::PAYLOAD_VERSION, 'sections' => []]);

        $template = LmsCourseTemplate::query()->create([
            'title' => $data['title'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'source_course_id' => $data['source_course_id'] ?? null,
            'payload' => $payload,
            'sections_count' => count($payload['sections'] ?? []),
            'items_count' => array_sum(array_map(fn (array $section) => count($section['items'] ?? []), $payload['sections'] ?? [])),
            'status' => $data['status'] ?? 'ACTIVE',
            'is_system' => false,
            'created_by' => $actor->id,
        ]);

        $this->audit('LMS_COURSE_TEMPLATE_CREATED', $template, ['title' => $template->title]);

        return $template;
    }

    /** @param array<string, mixed> $data */
    public function update(LmsCourseTemplate $template, array $data, LmsActor $actor): LmsCourseTemplate
    {
        foreach (['title', 'code', 'description', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $template->{$key} = $data[$key];
            }
        }

        if (array_key_exists('category_id', $data)) {
            $template->category_id = $data['category_id'];
        }

        if (array_key_exists('payload', $data)) {
            $payload = (array) $data['payload'];
            $template->payload = $payload;
            $template->sections_count = count($payload['sections'] ?? []);
            $template->items_count = array_sum(array_map(fn (array $section) => count($section['items'] ?? []), $payload['sections'] ?? []));
        }

        $template->save();

        $this->audit('LMS_COURSE_TEMPLATE_UPDATED', $template, ['title' => $template->title]);

        return $template->refresh();
    }

    public function delete(LmsCourseTemplate $template, LmsActor $actor): void
    {
        $this->audit('LMS_COURSE_TEMPLATE_DELETED', $template, ['title' => $template->title]);

        $template->delete();
    }

    /**
     * Terapkan template ke sebuah course (§48).
     *
     * Mode `APPEND` (default) menambahkan section di akhir; `REPLACE` hanya
     * diizinkan bila course belum punya peserta, supaya tidak menghapus jejak
     * belajar siswa yang sudah berjalan.
     *
     * @param  array<string, mixed>  $options  mode
     * @return array<string, mixed> statistik penerapan
     */
    public function apply(LmsCourseTemplate $template, LmsCourse $course, array $options, LmsActor $actor): array
    {
        $mode = strtoupper((string) ($options['mode'] ?? 'APPEND'));

        if ($mode === 'REPLACE') {
            if ($course->enrollments()->exists()) {
                LmsFault::conflict('Course sudah memiliki peserta — penghapusan struktur tidak diizinkan.');
            }

            DB::transaction(function () use ($course): void {
                foreach ($course->sections as $section) {
                    $this->content->deleteSection($section);
                }
            });
        }

        $stats = $this->applyPayload($course, (array) $template->payload, $actor);

        $this->audit('LMS_COURSE_TEMPLATE_APPLIED', $template, [
            'course_id' => (int) $course->id,
            'sections' => $stats['sections'],
            'items' => $stats['items'],
        ]);

        return $stats + ['mode' => $mode, 'template_id' => (int) $template->id, 'course_id' => (int) $course->id];
    }

    /**
     * Bangun payload template dari struktur course.
     *
     * @param  array<string, mixed>  $options  include_content, include_files, include_activities
     * @return array<string, mixed>
     */
    public function snapshotCourse(LmsCourse $course, array $options = []): array
    {
        $includeContent = (bool) ($options['include_content'] ?? true);
        $includeFiles = (bool) ($options['include_files'] ?? true);
        $includeActivities = (bool) ($options['include_activities'] ?? true);

        $sections = $course->sections()->with(['items.resource', 'items.activity'])->get();

        $payload = [
            'version' => self::PAYLOAD_VERSION,
            'title' => $course->name,
            'level' => $course->level,
            'language' => $course->language,
            'generated_at' => now()->toIso8601String(),
            'sections' => [],
        ];

        foreach ($sections as $section) {
            $rows = [];

            foreach ($section->items as $item) {
                $rows[] = $this->snapshotItem($item, $includeContent, $includeFiles, $includeActivities);
            }

            $payload['sections'][] = [
                'title' => $section->title,
                'description' => $section->description,
                'summary' => $section->summary,
                'visibility' => $section->visibility?->value ?? 'VISIBLE',
                'completion_rule' => $section->completion_rule ?? null,
                'availability' => $section->availability,
                'items' => $rows,
            ];
        }

        return $payload;
    }

    /**
     * Tulis payload template ke sebuah course (§48).
     *
     * @return array<string, mixed> statistik
     */
    public function applyPayload(LmsCourse $course, array $payload, LmsActor $actor): array
    {
        $sections = (array) ($payload['sections'] ?? []);
        $stats = ['sections' => 0, 'items' => 0, 'resources' => 0, 'activities' => 0];
        $position = (int) $course->sections()->max('sort_order');

        foreach ($sections as $sectionData) {
            $position++;
            $stats['sections']++;

            $section = $this->content->createSection($course, [
                'title' => (string) ($sectionData['title'] ?? 'Bagian '.$position),
                'description' => $sectionData['description'] ?? null,
                'summary' => $sectionData['summary'] ?? null,
                'visibility' => $sectionData['visibility'] ?? 'VISIBLE',
                'availability' => $sectionData['availability'] ?? null,
                'sort_order' => $position,
            ]);

            $sort = 0;

            foreach ((array) ($sectionData['items'] ?? []) as $itemData) {
                $sort++;
                $stats['items']++;

                $item = $this->content->createItem($section, [
                    'title' => (string) ($itemData['title'] ?? 'Materi '.$sort),
                    'description' => $itemData['description'] ?? null,
                    'item_type' => $itemData['item_type'] ?? ItemType::RESOURCE->value,
                    'resource_type' => $itemData['resource_type'] ?? null,
                    'activity_type' => $itemData['activity_type'] ?? null,
                    'sort_order' => $sort,
                    'visibility' => $itemData['visibility'] ?? 'VISIBLE',
                    'is_required' => $itemData['is_required'] ?? true,
                    'completion_type' => $itemData['completion_type'] ?? 'MANUAL',
                    'completion_min_grade' => $itemData['completion_min_grade'] ?? null,
                    'is_preview' => $itemData['is_preview'] ?? false,
                    'estimated_minutes' => $itemData['estimated_minutes'] ?? null,
                ], $actor);

                if (isset($itemData['resource']) && is_array($itemData['resource'])) {
                    $this->applyResource($item, $itemData['resource']);
                    $stats['resources']++;
                }

                if (isset($itemData['activity']) && is_array($itemData['activity'])) {
                    $this->applyActivity($item, $itemData['activity'], $actor);
                    $stats['activities']++;
                }
            }
        }

        return $stats;
    }

    /** Ringkasan isi template untuk pratinjau guru. */
    public function preview(LmsCourseTemplate $template): array
    {
        $payload = (array) $template->payload;

        return [
            'id' => (int) $template->id,
            'title' => $template->title,
            'version' => (int) ($payload['version'] ?? 0),
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'] ?? null,
                'items' => array_map(fn (array $item) => [
                    'title' => $item['title'] ?? null,
                    'item_type' => $item['item_type'] ?? null,
                    'has_resource' => isset($item['resource']),
                    'has_activity' => isset($item['activity']),
                ], (array) ($section['items'] ?? [])),
            ], (array) ($payload['sections'] ?? [])),
        ];
    }

    // ------------------------------------------------------------------ internal

    /** @return array<string, mixed> */
    private function snapshotItem(LmsLearningItem $item, bool $includeContent, bool $includeFiles, bool $includeActivities): array
    {
        $row = [
            'title' => $item->title,
            'description' => $item->description,
            'item_type' => $item->item_type->value,
            'resource_type' => $item->resource_type?->value,
            'activity_type' => $item->activity_type?->value,
            'visibility' => $item->visibility->value,
            'is_required' => (bool) $item->is_required,
            'completion_type' => $item->completion_type->value,
            'completion_min_grade' => $item->completion_min_grade === null ? null : (float) $item->completion_min_grade,
            'is_preview' => (bool) $item->is_preview,
            'estimated_minutes' => $item->estimated_minutes === null ? null : (int) $item->estimated_minutes,
        ];

        if ($item->resource !== null) {
            $resource = $item->resource;

            $row['resource'] = [
                'content' => $includeContent ? $resource->content : null,
                'external_url' => $resource->external_url,
                'open_in' => $resource->open_in,
                'media_source' => $resource->media_source,
                'video_provider' => $resource->video_provider,
                'duration_seconds' => $resource->duration_seconds === null ? null : (int) $resource->duration_seconds,
                'settings' => $resource->settings,
                'file' => $includeFiles && $resource->file_path !== null ? [
                    'file_path' => $resource->file_path,
                    'file_name' => $resource->file_name,
                    'file_mime' => $resource->file_mime,
                    'file_size' => $resource->file_size === null ? null : (int) $resource->file_size,
                ] : null,
            ];
        }

        if ($includeActivities && $item->activity !== null) {
            $activity = $item->activity;

            $row['activity'] = [
                'activity_type' => $activity->activity_type->value,
                'title' => $activity->title,
                'description' => $activity->description,
                'instructions' => $activity->instructions,
                'completion_rule' => $activity->completion_rule?->value,
                'completion_min_grade' => $activity->completion_min_grade === null ? null : (float) $activity->completion_min_grade,
                'is_graded' => (bool) $activity->is_graded,
                'settings' => $activity->settings,
                // Aktivitas selalu dibuat ulang sebagai DRAFT: template tidak
                // boleh menerbitkan tugas tanpa persetujuan guru (§48).
                'status' => 'DRAFT',
            ];
        }

        return $row;
    }

    /** @param array<string, mixed> $data */
    private function applyResource(LmsLearningItem $item, array $data): void
    {
        $resource = $item->resource;

        if ($resource === null) {
            return;
        }

        $resource->fill([
            'content' => $data['content'] ?? null,
            'external_url' => $data['external_url'] ?? null,
            'open_in' => $data['open_in'] ?? 'NEW_TAB',
            'media_source' => $data['media_source'] ?? null,
            'video_provider' => $data['video_provider'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'settings' => $data['settings'] ?? null,
        ]);

        if (isset($data['file']) && is_array($data['file'])) {
            // Berkas tidak digandakan: path yang sama dipakai bersama oleh kedua
            // course (berkas tidak pernah disimpan di database sejak Phase 1).
            $resource->fill([
                'file_path' => $data['file']['file_path'] ?? null,
                'file_name' => $data['file']['file_name'] ?? null,
                'file_mime' => $data['file']['file_mime'] ?? null,
                'file_size' => $data['file']['file_size'] ?? null,
            ]);
        }

        $resource->save();
    }

    /** @param array<string, mixed> $data */
    private function applyActivity(LmsLearningItem $item, array $data, LmsActor $actor): void
    {
        $type = ActivityType::tryFrom((string) ($data['activity_type'] ?? '')) ?? $item->activity_type;

        if ($type === null) {
            return;
        }

        $this->activities->createForItem($item, [
            'title' => $data['title'] ?? $item->title,
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'status' => 'DRAFT',
            'completion_rule' => $data['completion_rule'] ?? null,
            'completion_min_grade' => $data['completion_min_grade'] ?? null,
            'settings' => $data['settings'] ?? null,
            'is_graded' => $data['is_graded'] ?? null,
        ], $actor, $type);
    }

    /** @return array<string, mixed> */
    private function row(LmsCourseTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'title' => $template->title,
            'code' => $template->code,
            'description' => $template->description,
            'category_id' => $template->category_id === null ? null : (int) $template->category_id,
            'source_course_id' => $template->source_course_id === null ? null : (int) $template->source_course_id,
            'sections_count' => (int) $template->sections_count,
            'items_count' => (int) $template->items_count,
            'status' => $template->status,
            'is_system' => (bool) $template->is_system,
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }
}
