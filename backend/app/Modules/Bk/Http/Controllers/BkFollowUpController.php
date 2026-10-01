<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkFollowUpService;
use App\Modules\Bk\Models\BkFollowUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tindak lanjut lintas kasus: daftar milik saya, penyelesaian, penjadwalan
 * ulang, dan penandaan terlewat.
 */
class BkFollowUpController extends BaseApiController
{
    public function __construct(private readonly BkFollowUpService $followUps)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->followUps->paginate($request->user(), $request->only([
                'status', 'case_id', 'assigned_to', 'type', 'open', 'overdue', 'due_before',
            ]), (int) $request->integer('per_page', 20)),
            'Daftar tindak lanjut.',
        );
    }

    public function complete(Request $request, BkFollowUp $followUp): JsonResponse
    {
        $this->authorize('complete', $followUp);

        $validated = $request->validate([
            'outcome' => ['nullable', 'string', 'max:4000'],
            'next_action' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse($this->followUps->complete($request->user(), $followUp, $validated), 'Tindak lanjut selesai.');
    }

    public function reschedule(Request $request, BkFollowUp $followUp): JsonResponse
    {
        $this->authorize('reschedule', $followUp);

        $validated = $request->validate([
            'due_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->successResponse($this->followUps->reschedule($request->user(), $followUp, $validated['due_date'], $validated['reason'] ?? null), 'Tindak lanjut dijadwalkan ulang.');
    }

    public function miss(Request $request, BkFollowUp $followUp): JsonResponse
    {
        $this->authorize('complete', $followUp);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        return $this->successResponse($this->followUps->miss($request->user(), $followUp, $validated['reason'] ?? null), 'Tindak lanjut ditandai terlewat.');
    }
}
