<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseSearchService;
use App\Modules\LMS\Application\Services\LmsCategoryService;
use App\Modules\LMS\Application\Services\LmsIntegrationService;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsCourseResource;
use App\Modules\LMS\Models\LmsCategory;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permukaan publik LMS (§30): hanya course ber-visibility PUBLIC dan sudah
 * terbit yang pernah muncul di sini. Guest tidak dapat menembus materi privat
 * karena setiap pratinjau melewati `CourseAccessService`.
 */
class LmsPublicController extends LmsController
{
    public function __construct(
        private readonly LmsCategoryService $categories,
        private readonly CourseSearchService $search,
        private readonly TeacherProviderInterface $teachers,
        private readonly CourseAccessService $access,
        private readonly LmsIntegrationService $integration,
    ) {}

    /** GET /lms/public/home */
    public function home(): JsonResponse
    {
        $featured = LmsCourse::query()
            ->publiclyVisible()
            ->with(['category:id,name,slug', 'teachers:id,course_id,name,is_primary'])
            ->withCount(['sections', 'items'])
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        return $this->successResponse([
            'hero' => [
                'title' => 'Belajar tanpa batas bersama LMS sekolah',
                'subtitle' => 'Materi, aktivitas, dan progress belajar dalam satu tempat — untuk guru, siswa, dan orang tua.',
                'stats' => [
                    'courses' => (int) LmsCourse::query()->publiclyVisible()->count(),
                    'categories' => (int) LmsCategory::query()->active()->count(),
                    'lessons' => (int) LmsLearningItem::query()
                        ->whereHas('course', fn (Builder $q) => $q->where('visibility', CourseVisibility::PUBLIC->value))
                        ->count(),
                ],
            ],
            'featured_courses' => LmsCourseResource::collection($featured),
            'categories' => $this->categories->tree(true),
            'teachers' => array_map(fn ($teacher) => $teacher->toArray(), $this->teachers->search('', 8)),
            'integration' => $this->integration->status(),
        ], 'Halaman utama LMS berhasil diambil');
    }

    /** GET /lms/public/courses */
    public function courses(Request $request): JsonResponse
    {
        $query = LmsCourse::query()
            ->publiclyVisible()
            ->with(['category:id,name,slug', 'teachers:id,course_id,name,is_primary'])
            ->withCount(['sections', 'items', 'students as enrolled_students_count'])
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->query('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%"));
            })
            ->when($request->filled('category_id'), fn (Builder $q) => $q->where('category_id', (int) $request->query('category_id')))
            ->when($request->filled('level'), fn (Builder $q) => $q->where('level', $request->query('level')))
            ->when($request->filled('course_type'), fn (Builder $q) => $q->where('course_type', $request->query('course_type')))
            ->when($request->boolean('featured'), fn (Builder $q) => $q->where('is_featured', true));

        $sort = (string) $request->query('sort', 'newest');

        match ($sort) {
            'name' => $query->orderBy('name'),
            'popular' => $query->orderByDesc('enrolled_students_count'),
            default => $query->orderByDesc('published_at'),
        };

        return $this->successResponse(
            LmsCourseResource::collection($query->paginate($this->perPage($request, 12))->appends($request->query())),
            'Katalog course publik berhasil diambil',
        );
    }

    /** GET /lms/public/courses/{slug} */
    public function course(string $slug): JsonResponse
    {
        $course = LmsCourse::query()
            ->where('slug', $slug)
            ->where('status', CourseStatus::PUBLISHED->value)
            ->where('visibility', CourseVisibility::PUBLIC->value)
            ->with(['category:id,name,slug', 'teachers:id,course_id,name,is_primary', 'sections.items.resource'])
            ->firstOrFail();

        $previewItems = $course->sections
            ->flatMap(fn ($section) => $section->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'title' => (string) $item->title,
                'item_type' => $item->item_type?->value,
                'resource_type' => $item->resource_type?->value,
                'activity_type' => $item->activity_type?->value,
                'is_preview' => (bool) $item->is_preview,
                'section_title' => $section->title,
                'locked' => ! $this->access->decide($course, null, $item)->allowed,
            ]))
            ->values()
            ->all();

        return $this->successResponse([
            'course' => (new LmsCourseResource($course))->additional(['stats' => [
                'sections' => $course->sections->count(),
                'items' => $course->sections->sum(fn ($section) => $section->items->count()),
                'students' => $course->enrollments()->where('role', 'STUDENT')->count(),
            ]]),
            'sections' => $course->sections->map(fn ($section) => [
                'id' => (int) $section->id,
                'title' => (string) $section->title,
                'summary' => $section->summary,
                'items_count' => $section->items->count(),
            ])->values()->all(),
            'preview_items' => $previewItems,
            'syllabus_locked' => true,
        ], 'Detail course publik berhasil diambil');
    }

    /** GET /lms/public/categories */
    public function categories(): JsonResponse
    {
        return $this->successResponse(
            $this->categories->tree(true),
            'Kategori publik berhasil diambil',
        );
    }

    /** GET /lms/public/teachers — guru yang memiliki course publik terbit. */
    public function teachers(Request $request): JsonResponse
    {
        $term = (string) $request->query('search', '');

        $teacherIds = LmsCourse::query()
            ->publiclyVisible()
            ->with('teachers:id,course_id,employee_id,name')
            ->get()
            ->flatMap(fn (LmsCourse $course) => $course->teachers->pluck('employee_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $teachers = $term !== '' || $teacherIds === []
            ? $this->teachers->search($term, 24)
            : collect($teacherIds)->map(fn (int $id) => $this->teachers->find($id))->filter()->values()->all();

        return $this->successResponse(
            array_map(fn ($teacher) => $teacher->toArray() + [
                'public_courses' => (int) LmsCourse::query()
                    ->publiclyVisible()
                    ->whereHas('teachers', fn (Builder $q) => $q->where('employee_id', $teacher->id))
                    ->count(),
            ], $teachers),
            'Daftar guru pengajar berhasil diambil',
        );
    }

    /** GET /lms/public/about */
    public function about(): JsonResponse
    {
        return $this->successResponse([
            'about' => 'LMS Syntaf School menghubungkan materi pembelajaran, aktivitas, dan penilaian dalam satu alur yang terhubung dengan SIAKAD sekolah.',
            'features' => [
                ['title' => 'Course terstruktur', 'description' => 'Materi disusun per bagian dengan aktivitas yang terukur.'],
                ['title' => 'Progress transparan', 'description' => 'Siswa, guru, dan orang tua melihat progress yang sama.'],
                ['title' => 'Terhubung SIAKAD', 'description' => 'Peserta course akademik tersinkron dari rombel, tanpa input ulang.'],
                ['title' => 'Portal orang tua', 'description' => 'Wali memantau belajar anak tanpa bisa mengubah nilai atau tugas.'],
            ],
            'integration' => $this->integration->status(),
            'search_preview' => $this->search->suggestions('', null, 4),
        ], 'Informasi LMS berhasil diambil');
    }
}
