<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Umpan balik siswa & orang tua.
 *
 * Setelah final, isi umpan balik tidak dapat diubah siapa pun; konselor hanya dapat
 * menandai sudah membaca. Aturan itu ditegakkan layanan, bukan disembunyikan di UI.
 */
class BkFeedbackController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkFeedbackService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.feedback.view') || abort(403);

        return $this->successResponse($this->service->paginate($request->user(), $request->only([
            'subject_type', 'subject_id', 'student_id', 'status', 'min_rating',
        ]), (int) $request->input('per_page', 25)));
    }

    public function storeStudent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_id' => ['nullable', 'integer'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->service->submitByStudent($request->user(), $data), 'Umpan balikmu terkirim. Terima kasih.');
    }

    public function storeGuardian(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'subject_type' => ['required', 'string'],
            'subject_id' => ['nullable', 'integer'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'is_anonymous' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->service->submitByGuardian($request->user(), $data), 'Umpan balik Anda terkirim.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $feedback = \App\Modules\Bk\Models\BkFeedback::query()->findOrFail($id);

        $data = $request->validate([
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse($this->service->updateComment($request->user(), $feedback, $data), 'Umpan balik diperbarui.');
    }

    public function finalize(Request $request, int $id): JsonResponse
    {
        $feedback = \App\Modules\Bk\Models\BkFeedback::query()->findOrFail($id);

        return $this->successResponse($this->service->finalize($request->user(), $feedback), 'Umpan balik difinalkan dan tidak dapat diubah lagi.');
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $feedback = \App\Modules\Bk\Models\BkFeedback::query()->findOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        return $this->successResponse($this->service->withdraw($request->user(), $feedback, $data['reason'] ?? null), 'Umpan balik ditarik.');
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.feedback.review') || abort(403);

        $feedback = \App\Modules\Bk\Models\BkFeedback::query()->findOrFail($id);
        $data = $request->validate(['note' => ['nullable', 'string']]);

        return $this->successResponse($this->service->markReviewed($request->user(), $feedback, $data['note'] ?? null), 'Umpan balik ditandai sudah dibaca.');
    }
}
