<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\LessonPageType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLesson;
use App\Modules\LMS\Models\LmsLessonBranch;
use App\Modules\LMS\Models\LmsLessonPage;
use App\Modules\LMS\Models\LmsLessonProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lesson dengan halaman bercabang (§37).
 *
 * Struktur: Lesson → Page → (opsional) Question → Branch → Page berikutnya.
 * Jawaban salah dapat diarahkan ke halaman penguatan, jawaban benar melanjutkan
 * ke halaman materi berikutnya; penyelesaian dihitung dari halaman wajib (§40).
 */
class LessonService implements ActivityConfiguratorInterface
{
    use LogsLmsAudit;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ActivityCompletionService $completion,
    ) {}

    public function createForActivity(LmsActivity $activity, array $data): void
    {
        $lesson = LmsLesson::create([
            'activity_id' => $activity->id,
            'require_all_pages' => $data['require_all_pages'] ?? true,
            'max_attempts' => $data['max_attempts'] ?? 0,
            'allow_review' => $data['allow_review'] ?? true,
            'settings' => $data['lesson_settings'] ?? null,
        ]);

        foreach ((array) ($data['pages'] ?? []) as $page) {
            $this->createPage($lesson, $page);
        }
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $lesson = $this->config($activity);

        if ($lesson === null) {
            return;
        }

        $lesson->fill(collect($data)->only(['require_all_pages', 'max_attempts', 'allow_review'])->all());

        if (array_key_exists('lesson_settings', $data)) {
            $lesson->settings = $data['lesson_settings'];
        }

        $lesson->save();
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $lesson = $this->config($source);

        if ($lesson === null) {
            return;
        }

        DB::transaction(function () use ($lesson, $copy): void {
            $newLesson = LmsLesson::create([
                'activity_id' => $copy->id,
                'require_all_pages' => $lesson->require_all_pages,
                'max_attempts' => $lesson->max_attempts,
                'allow_review' => $lesson->allow_review,
                'settings' => $lesson->settings,
            ]);

            // Salin halaman + cabang dengan pemetaan id lama → id baru.
            $map = [];

            foreach ($lesson->pages()->orderBy('sort_order')->get() as $page) {
                $created = $newLesson->pages()->create([
                    'title' => $page->title,
                    'content' => $page->content,
                    'page_type' => $page->page_type?->value,
                    'question_id' => $page->question_id,
                    'is_required' => $page->is_required,
                    'sort_order' => $page->sort_order,
                ]);

                $map[(int) $page->id] = $created->id;
            }

            foreach ($lesson->pages()->orderBy('sort_order')->get() as $page) {
                foreach ($page->branches as $branch) {
                    $newLesson->pages()->find($map[(int) $page->id])?->branches()->create([
                        'next_page_id' => $branch->next_page_id === null ? null : ($map[(int) $branch->next_page_id] ?? null),
                        'label' => $branch->label,
                        'is_correct' => $branch->is_correct,
                        'answer_key' => $branch->answer_key,
                        'sort_order' => $branch->sort_order,
                    ]);
                }
            }
        });
    }

    public function config(LmsActivity $activity): ?LmsLesson
    {
        return $activity->lesson ?? $activity->load('lesson')->lesson;
    }

    public function ensureConfig(LmsActivity $activity): LmsLesson
    {
        $lesson = $this->config($activity);

        if ($lesson === null) {
            $this->createForActivity($activity, []);
            $lesson = $this->config($activity->fresh('lesson'));
        }

        return $lesson;
    }

    // ------------------------------------------------------------------ halaman

    /** @param array<string, mixed> $data */
    public function createPage(LmsLesson $lesson, array $data): LmsLessonPage
    {
        return $lesson->pages()->create([
            'title' => $data['title'] ?? 'Halaman '.((int) $lesson->pages()->max('sort_order') + 1),
            'content' => $this->sanitizer->clean($data['content'] ?? null),
            'page_type' => $data['page_type'] ?? LessonPageType::CONTENT->value,
            'question_id' => $data['question_id'] ?? null,
            'is_required' => $data['is_required'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) $lesson->pages()->max('sort_order') + 1),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updatePage(LmsLessonPage $page, array $data): LmsLessonPage
    {
        if (array_key_exists('content', $data)) {
            $data['content'] = $this->sanitizer->clean($data['content']);
        }

        $page->fill(collect($data)->only(['title', 'content', 'page_type', 'question_id', 'is_required', 'sort_order'])->all())->save();

        if (array_key_exists('branches', $data)) {
            $page->branches()->delete();

            foreach ((array) $data['branches'] as $index => $branch) {
                $page->branches()->create([
                    'next_page_id' => $branch['next_page_id'] ?? null,
                    'label' => $branch['label'] ?? null,
                    'is_correct' => $branch['is_correct'] ?? true,
                    'answer_key' => $branch['answer_key'] ?? null,
                    'sort_order' => $branch['sort_order'] ?? $index + 1,
                ]);
            }
        }

        return $page->fresh(['branches', 'question.options']);
    }

    public function deletePage(LmsLessonPage $page): void
    {
        $lesson = $page->lesson;

        if ($lesson !== null && $lesson->pages()->count() <= 1) {
            throw ValidationException::withMessages([
                'page' => 'Lesson harus memiliki minimal satu halaman.',
            ]);
        }

        $page->delete();
    }

    /** @param list<int> $pageIds */
    public function reorderPages(LmsLesson $lesson, array $pageIds): void
    {
        foreach (array_values($pageIds) as $index => $id) {
            $lesson->pages()->whereKey($id)->update(['sort_order' => $index + 1]);
        }
    }

    // ------------------------------------------------------------------ alur belajar

    /**
     * Payload halaman untuk siswa: materi, pertanyaan (tanpa kunci), navigasi.
     *
     * @return array<string, mixed>
     */
    public function pageView(LmsLesson $lesson, ?int $pageId, int $userId): array
    {
        $pages = $lesson->pages()->with(['question.options', 'branches'])->get();

        if ($pages->isEmpty()) {
            return ['lesson' => $this->configPayload($lesson), 'pages' => [], 'current' => null, 'progress' => null];
        }

        $current = $pageId === null
            ? $pages->first()
            : ($pages->firstWhere('id', $pageId) ?? $pages->first());

        $progress = $this->progressFor($lesson, $userId, createIfMissing: false);

        return [
            'lesson' => $this->configPayload($lesson),
            'pages' => $pages->map(fn (LmsLessonPage $page) => [
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'page_type' => $page->page_type?->value,
                'is_required' => (bool) $page->is_required,
                'sort_order' => (int) $page->sort_order,
                'visited' => $progress !== null && in_array((int) $page->id, $progress->visitedPageIds(), true),
            ])->all(),
            'current' => $this->pagePayload($current),
            'progress' => $progress === null ? null : [
                'visited_pages' => $progress->visitedPageIds(),
                'completed_at' => $progress->completed_at?->toIso8601String(),
                'percent' => $this->percent($lesson, $progress),
            ],
            'nav' => $this->navigationFor($lesson, $current),
        ];
    }

    /** @return array<string, mixed> */
    public function pagePayload(LmsLessonPage $page): array
    {
        return [
            'id' => (int) $page->id,
            'title' => (string) $page->title,
            'content' => $page->content,
            'page_type' => $page->page_type?->value,
            'is_required' => (bool) $page->is_required,
            'sort_order' => (int) $page->sort_order,
            // Kunci jawaban tidak pernah dikirim ke siswa (§28 analog untuk lesson).
            'question' => $page->question === null ? null : [
                'id' => (int) $page->question->id,
                'question_type' => $page->question->question_type?->value,
                'question_text' => $page->question->question_text,
                'options' => $page->question->options->map(fn ($option) => [
                    'id' => (int) $option->id,
                    'option_key' => $option->option_key,
                    'text' => $option->text,
                ])->all(),
            ],
            'branches' => $page->branches->map(fn (LmsLessonBranch $branch) => [
                'id' => (int) $branch->id,
                'label' => $branch->label,
                'answer_key' => $branch->answer_key,
                'is_correct' => (bool) $branch->is_correct,
                'next_page_id' => $branch->next_page_id === null ? null : (int) $branch->next_page_id,
            ])->all(),
        ];
    }

    /**
     * Catat kunjungan halaman + jawaban (bila halaman bertipe QUESTION).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordView(LmsLesson $lesson, LmsLessonPage $page, LmsEnrollment $enrollment, array $data = []): array
    {
        $progress = $this->progressFor($lesson, $enrollment->user_id, createIfMissing: true);
        $progress->markVisited((int) $page->id);

        $nextPage = null;
        $answerCorrect = null;

        if ($page->isQuestion() && isset($data['answer_key'])) {
            $answerKey = (string) $data['answer_key'];
            $branch = $page->branches->firstWhere('answer_key', $answerKey) ?? $page->branches->firstWhere('is_correct', true);
            $answerCorrect = (bool) ($branch?->is_correct ?? false);
            $nextPage = $branch?->nextPage;
        }

        if ($nextPage === null) {
            $nextPage = $page->branches->firstWhere('is_correct', true)?->nextPage
                ?? $this->sequentialNext($lesson, $page);
        }

        // Halaman berikutnya hanya menjadi tujuan navigasi — bukan otomatis
        // dianggap sudah dibaca (progres harus mencerminkan kunjungan nyata).
        $this->evaluateCompletion($lesson, $enrollment, $progress);

        return [
            'answer_correct' => $answerCorrect,
            'next_page' => $nextPage === null ? null : $this->pagePayload($nextPage->fresh(['question.options', 'branches'])),
            'progress' => [
                'visited_pages' => $progress->visitedPageIds(),
                'percent' => $this->percent($lesson, $progress),
                'completed' => $progress->completed_at !== null,
            ],
        ];
    }

    /** Selesaikan lesson bila seluruh halaman wajib sudah dikunjungi (§40). */
    public function evaluateCompletion(LmsLesson $lesson, LmsEnrollment $enrollment, ?LmsLessonProgress $progress = null): void
    {
        $progress ??= $this->progressFor($lesson, $enrollment->user_id, createIfMissing: false);

        if ($progress === null) {
            return;
        }

        $required = $lesson->requiredPageIds();
        $visited = $progress->visitedPageIds();

        $missing = array_diff($required, $visited);

        if ($missing !== []) {
            return;
        }

        if ($progress->completed_at === null) {
            $progress->forceFill(['completed_at' => now()])->save();
        }

        $activity = $lesson->activity;

        if ($activity !== null) {
            $this->completion->complete($activity, $enrollment, null, 'lesson_completed', $enrollment->user_id, [
                'pages' => count($visited),
            ]);
        }
    }

    public function progressFor(LmsLesson $lesson, int $userId, bool $createIfMissing): ?LmsLessonProgress
    {
        $progress = LmsLessonProgress::query()
            ->where('lesson_id', $lesson->id)
            ->where('user_id', $userId)
            ->first();

        if ($progress === null && $createIfMissing) {
            $progress = LmsLessonProgress::create([
                'lesson_id' => $lesson->id,
                'user_id' => $userId,
                'started_at' => now(),
                'visited_pages' => [],
            ]);
        }

        return $progress;
    }

    public function percent(LmsLesson $lesson, LmsLessonProgress $progress): float
    {
        $required = $lesson->requiredPageIds();

        if ($required === []) {
            return 0.0;
        }

        $visited = array_intersect($required, $progress->visitedPageIds());

        return round(count($visited) / count($required) * 100, 2);
    }

    /** @return array<string, mixed> */
    public function configPayload(LmsLesson $lesson): array
    {
        return [
            'id' => (int) $lesson->id,
            'require_all_pages' => (bool) $lesson->require_all_pages,
            'max_attempts' => (int) $lesson->max_attempts,
            'allow_review' => (bool) $lesson->allow_review,
            'pages_count' => $lesson->pages()->count(),
            'settings' => $lesson->settings,
        ];
    }

    /** Statistik lesson untuk guru. */
    public function statistics(LmsActivity $activity): array
    {
        $lesson = $this->ensureConfig($activity);

        $participants = LmsEnrollment::query()
            ->where('course_id', $activity->course_id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->count();

        $progress = LmsLessonProgress::query()->where('lesson_id', $lesson->id)->get();
        $completed = $progress->whereNotNull('completed_at')->count();

        $perPage = $lesson->pages()->withCount('branches')->get()->map(fn (LmsLessonPage $page) => [
            'id' => (int) $page->id,
            'title' => (string) $page->title,
            'sort_order' => (int) $page->sort_order,
            'is_required' => (bool) $page->is_required,
            'branches' => (int) $page->branches_count,
            'visits' => $progress->filter(fn (LmsLessonProgress $item) => in_array((int) $page->id, $item->visitedPageIds(), true))->count(),
        ])->all();

        return [
            'participants' => $participants,
            'started' => $progress->count(),
            'completed' => $completed,
            'completion_rate' => $participants > 0 ? round($completed / $participants * 100, 2) : null,
            'pages' => $perPage,
        ];
    }

    protected function sequentialNext(LmsLesson $lesson, LmsLessonPage $page): ?LmsLessonPage
    {
        return $lesson->pages()
            ->where('sort_order', '>', $page->sort_order)
            ->orderBy('sort_order')
            ->first();
    }

    /** @return array<string, mixed> */
    protected function navigationFor(LmsLesson $lesson, LmsLessonPage $current): array
    {
        $pages = $lesson->pages()->orderBy('sort_order')->get();
        $index = $pages->search(fn (LmsLessonPage $page) => (int) $page->id === (int) $current->id);

        $previous = $index === false ? null : $pages->get($index - 1);
        $next = $index === false ? null : $pages->get($index + 1);

        return [
            'previous' => $previous === null ? null : ['id' => (int) $previous->id, 'title' => (string) $previous->title],
            'next' => $next === null ? null : ['id' => (int) $next->id, 'title' => (string) $next->title],
            'total' => $pages->count(),
            'position' => $index === false ? null : $index + 1,
        ];
    }
}
