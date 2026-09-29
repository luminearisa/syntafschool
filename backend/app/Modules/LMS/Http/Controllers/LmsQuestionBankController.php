<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\QuestionBankService;
use App\Modules\LMS\Application\Services\QuestionService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsQuestionBank;
use App\Modules\LMS\Models\LmsQuestionCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bank soal & kategori (§14, §53).
 *
 * Guru dapat memiliki bank pribadi per course; bank institusi (course_id null)
 * dipakai bersama dan hanya boleh diubah pemegang `lms.questionbank.manage`
 * pada level administrasi.
 */
class LmsQuestionBankController extends LmsController
{
    public function __construct(
        private readonly QuestionBankService $banks,
        private readonly QuestionService $questions,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $course = $request->query('course_id') ? LmsCourse::query()->find((int) $request->query('course_id')) : null;

        $banks = $this->banks->all($course);

        return $this->successResponse(
            $banks->map(fn (LmsQuestionBank $bank) => [
                'id' => (int) $bank->id,
                'name' => (string) $bank->name,
                'description' => $bank->description,
                'course_id' => $bank->course_id === null ? null : (int) $bank->course_id,
                'visibility' => $bank->visibility,
                'status' => $bank->status,
                'questions_count' => (int) ($bank->questions_count ?? $bank->questions()->count()),
                'categories_count' => (int) ($bank->categories_count ?? $bank->categories()->count()),
            ])->all() + ['can_manage' => $actor->can('lms.questionbank.manage')],
            'Daftar bank soal berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'visibility' => ['nullable', 'string', 'in:COURSE,INSTITUTION'],
        ]);

        if (isset($data['course_id'])) {
            $course = LmsCourse::query()->findOrFail((int) $data['course_id']);
            $this->authorizeLms($this->access->canManage($course, $actor), 'Anda tidak berhak membuat bank soal pada course ini.');
        }

        $bank = $this->banks->create($data, $actor);

        return $this->createdResponse([
            'id' => (int) $bank->id,
            'name' => (string) $bank->name,
        ], 'Bank soal berhasil dibuat');
    }

    /** Struktur bank → kategori → jumlah soal (§14). */
    public function show(Request $request, LmsQuestionBank $bank): JsonResponse
    {
        return $this->successResponse($this->banks->tree($bank), 'Struktur bank soal berhasil diambil');
    }

    public function update(Request $request, LmsQuestionBank $bank): JsonResponse
    {
        $this->authorizeLms($this->canTouchBank($request, $bank));

        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:ACTIVE,DRAFT,ARCHIVED'],
            'visibility' => ['nullable', 'string', 'in:COURSE,INSTITUTION'],
        ]);

        $this->banks->update($bank, $data);

        return $this->successResponse($this->banks->tree($bank->fresh()), 'Bank soal berhasil diperbarui');
    }

    public function destroy(Request $request, LmsQuestionBank $bank): JsonResponse
    {
        $this->authorizeLms($this->canTouchBank($request, $bank));

        $this->banks->delete($bank);

        return $this->successResponse(null, 'Bank soal berhasil dihapus');
    }

    public function storeCategory(Request $request, LmsQuestionBank $bank): JsonResponse
    {
        $this->authorizeLms($this->canTouchBank($request, $bank));

        $data = $this->validatePayload($request, [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_question_categories,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->banks->createCategory($bank, $data);

        return $this->createdResponse($this->banks->tree($bank->fresh()), 'Kategori soal berhasil dibuat');
    }

    public function updateCategory(Request $request, LmsQuestionCategory $category): JsonResponse
    {
        $this->authorizeLms($this->canTouchBank($request, $category->bank));

        $data = $this->validatePayload($request, [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->banks->updateCategory($category, $data);

        return $this->successResponse($this->banks->tree($category->bank->fresh()), 'Kategori berhasil diperbarui');
    }

    public function destroyCategory(Request $request, LmsQuestionCategory $category): JsonResponse
    {
        $this->authorizeLms($this->canTouchBank($request, $category->bank));

        $bank = $category->bank;
        $this->banks->deleteCategory($category);

        return $this->successResponse($this->banks->tree($bank->fresh()), 'Kategori berhasil dihapus');
    }

    /** Soal-soal di dalam sebuah bank (dengan filter, §14). */
    public function questions(Request $request, LmsQuestionBank $bank): JsonResponse
    {
        $filters = $request->only(['category_id', 'question_type', 'difficulty', 'search']) + ['bank_id' => $bank->id];

        return $this->successResponse(
            $this->questions->list($filters, $this->perPage($request, 25)),
            'Daftar soal berhasil diambil',
        );
    }

    protected function canTouchBank(Request $request, ?LmsQuestionBank $bank): bool
    {
        $actor = $this->actor($request);

        if ($actor->can('lms.manage')) {
            return true;
        }

        if ($bank?->course_id === null) {
            return false;
        }

        $course = LmsCourse::query()->find($bank->course_id);

        return $course !== null && $this->access->canManage($course, $actor);
    }
}
