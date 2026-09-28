<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\QuestionService;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Soal & versioning (§14–§22, §53).
 */
class LmsQuestionController extends LmsController
{
    public function __construct(
        private readonly QuestionService $questions,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->questions->list($request->only([
                'bank_id', 'category_id', 'course_id', 'question_type', 'difficulty', 'search', 'include_archived',
            ]), $this->perPage($request, 25)),
            'Daftar soal berhasil diambil',
        );
    }

    /** Buat soal — guru pemilik course atau pemegang `lms.question.create` (§15). */
    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.question.create') || $actor->can('lms.manage'), 'Anda tidak berhak membuat soal.');

        $data = $this->validatePayload($request, $this->rules());

        $question = $this->questions->create($data, $actor);

        return $this->createdResponse($this->payload($question), 'Soal berhasil dibuat');
    }

    public function show(Request $request, LmsQuestion $question): JsonResponse
    {
        return $this->successResponse(
            $this->payload($question) + ['versions' => $this->questions->versions($question)->map(fn ($version) => [
                'id' => (int) $version->id,
                'version' => (int) $version->version,
                'question_text' => $version->question_text,
                'default_mark' => (float) $version->default_mark,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->all()],
            'Detail soal berhasil diambil',
        );
    }

    public function update(Request $request, LmsQuestion $question): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->canTouchCourse($request, $question->course_id), 'Anda tidak berhak mengubah soal ini.');

        $data = $this->validatePayload($request, $this->rules(updating: true));

        return $this->successResponse($this->payload($this->questions->update($question, $data, $actor)), 'Soal berhasil diperbarui');
    }

    public function destroy(Request $request, LmsQuestion $question): JsonResponse
    {
        $this->authorizeLms($this->canTouchCourse($request, $question->course_id), 'Anda tidak berhak menghapus soal ini.');

        $this->questions->delete($question);

        return $this->successResponse(null, 'Soal dihapus atau diarsipkan karena sudah dipakai pada percobaan');
    }

    public function duplicate(Request $request, LmsQuestion $question): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->canTouchCourse($request, $question->course_id), 'Anda tidak berhak menyalin soal ini.');

        $copy = $this->questions->duplicate($question, $actor, $request->only(['category_id', 'bank_id']));

        return $this->createdResponse($this->payload($copy), 'Soal berhasil diduplikasi');
    }

    /** @return array<string, mixed> */
    protected function payload(LmsQuestion $question): array
    {
        return [
            'id' => (int) $question->id,
            'bank_id' => $question->bank_id === null ? null : (int) $question->bank_id,
            'category_id' => $question->category_id === null ? null : (int) $question->category_id,
            'course_id' => $question->course_id === null ? null : (int) $question->course_id,
            'name' => (string) $question->name,
            'question_type' => $question->question_type?->value,
            'question_type_label' => $question->question_type?->label(),
            'question_text' => $question->question_text,
            'general_feedback' => $question->general_feedback,
            'default_mark' => (float) $question->default_mark,
            'difficulty' => $question->difficulty?->value,
            'status' => $question->status,
            'version' => (int) $question->version,
            'settings' => $question->settings,
            'options' => $question->options->map(fn ($option) => [
                'id' => (int) $option->id,
                'option_key' => $option->option_key,
                'text' => $option->text,
                'is_correct' => (bool) $option->is_correct,
                'feedback' => $option->feedback,
                'match_target' => $option->match_target,
                'sort_order' => (int) $option->sort_order,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'bank_id' => ['nullable', 'integer', 'exists:lms_question_banks,id'],
            'category_id' => ['nullable', 'integer', 'exists:lms_question_categories,id'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'question_type' => [$required, 'string', 'in:'.implode(',', \App\Modules\LMS\Domain\Enums\QuestionType::values())],
            'question_text' => [$required, 'string'],
            'general_feedback' => ['nullable', 'string'],
            'default_mark' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'difficulty' => ['nullable', 'string', 'in:EASY,MEDIUM,HARD'],
            'status' => ['nullable', 'string', 'in:ACTIVE,DRAFT,ARCHIVED'],
            'settings' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'options.*.option_key' => ['nullable', 'string', 'max:10'],
            'options.*.text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.feedback' => ['nullable', 'string'],
            'options.*.match_target' => ['nullable', 'string', 'max:255'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ];
    }

    protected function canTouchCourse(Request $request, ?int $courseId): bool
    {
        $actor = $this->actor($request);

        if ($actor->can('lms.manage')) {
            return true;
        }

        if ($courseId === null) {
            return $actor->can('lms.question.update');
        }

        $course = LmsCourse::query()->find($courseId);

        return $course !== null && $this->access->canManage($course, $actor);
    }
}
