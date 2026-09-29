<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\GradeSyncQueueService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsGradebook;
use App\Modules\LMS\Models\LmsGradeSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Antrean sinkronisasi nilai LMS → SIAKAD (§6).
 *
 * Sync Now, Retry Failed, View History, Compare LMS vs SIAKAD — semuanya
 * melewati `GradeSyncQueueService` sehingga aturan penguncian dan idempotensi
 * tetap satu tempat.
 */
class LmsGradeSyncQueueController extends LmsController
{
    public function __construct(
        private readonly GradeSyncQueueService $queue,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/gradebooks/{gradebook}/sync */
    public function status(Request $request, int $gradebook): JsonResponse
    {
        $model = $this->gradebook($gradebook);
        $this->authorizeManage($model);

        return $this->successResponse($this->queue->status($model));
    }

    /** POST /lms/gradebooks/{gradebook}/sync */
    public function enqueue(Request $request, int $gradebook): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->gradebook($gradebook);
        $this->authorizeManage($model);

        $data = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'dispatch' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->queue->enqueue($model, (array) ($data['user_ids'] ?? []), $actor, (bool) ($data['dispatch'] ?? true)),
            'Sinkronisasi diantrekan.',
        );
    }

    /** POST /lms/gradebooks/{gradebook}/sync/retry */
    public function retry(Request $request, int $gradebook): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->gradebook($gradebook);
        $this->authorizeManage($model);

        return $this->successResponse($this->queue->retryFailed($model, $actor), 'Sinkronisasi gagal dicoba ulang.');
    }

    /** GET /lms/grade-syncs/{sync}/history */
    public function history(Request $request, int $sync): JsonResponse
    {
        $model = $this->sync($sync);
        $this->authorizeManage($model->gradebook()->firstOrFail());

        return $this->successResponse(['attempts' => $this->queue->history($model, (int) $this->perPage($request, 20))]);
    }

    /** POST /lms/grade-syncs/{sync}/cancel */
    public function cancel(Request $request, int $sync): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->sync($sync);
        $this->authorizeManage($model->gradebook()->firstOrFail());

        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        return $this->successResponse(
            $this->queue->cancel($model, $actor, (string) $data['reason']),
            'Sinkronisasi dibatalkan.',
        );
    }

    /** POST /lms/grade-syncs/{sync}/process */
    public function process(Request $request, int $sync): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->sync($sync);
        $this->authorizeManage($model->gradebook()->firstOrFail());

        return $this->successResponse($this->queue->process($model, $actor), 'Sinkronisasi diproses.');
    }

    /** GET /lms/gradebooks/{gradebook}/sync/compare/{userId} */
    public function compare(Request $request, int $gradebook, int $userId): JsonResponse
    {
        $model = $this->gradebook($gradebook);
        $this->authorizeManage($model);

        return $this->successResponse($this->queue->compare($model, $userId), 'Perbandingan nilai LMS vs SIAKAD.');
    }

    private function gradebook(int $id): LmsGradebook
    {
        $gradebook = LmsGradebook::query()->with('course')->find($id);
        abort_if($gradebook === null, 404, 'Gradebook tidak ditemukan.');

        return $gradebook;
    }

    private function sync(int $id): LmsGradeSync
    {
        $sync = LmsGradeSync::query()->find($id);
        abort_if($sync === null, 404, 'Data sinkronisasi tidak ditemukan.');

        return $sync;
    }

    private function authorizeManage(LmsGradebook $gradebook): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            ($actor->can('lms.grade.sync') || $actor->can('lms.manage')) && $this->access->canGrade($gradebook->course, $actor),
            'Anda tidak berhak menyinkronkan nilai course ini.',
        );
    }
}
