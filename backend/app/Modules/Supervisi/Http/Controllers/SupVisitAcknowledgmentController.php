<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Models\Employee;
use App\Modules\Supervisi\Application\Services\VisitAccessService;
use App\Modules\Supervisi\Application\Services\VisitAcknowledgmentService;
use App\Modules\Supervisi\Models\SupDispute;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tanda tangan hasil & keberatan.
 *
 * Guru menandai hasil sudah dibaca, lalu boleh mengajukan keberatan; moderator
 * memutuskan. Guru tidak dapat memfinalkan maupun mengubah nilai — hak itu
 * hanya milik supervisor berwenang.
 */
class SupVisitAcknowledgmentController extends SupController
{
    public function __construct(
        private readonly VisitAcknowledgmentService $acknowledgments,
        private readonly VisitAccessService $access,
    ) {}

    /** Tandai hasil sudah dibaca/diterima (oleh yang disupervisi). */
    public function acknowledge(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanAcknowledge($actor, $visit);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $employee = $this->employee($actor);
        $acknowledgment = $this->acknowledgments->acknowledge($visit, $employee, $data['note'] ?? null, $request->ip());

        return $this->successResponse([
            'acknowledged_at' => $acknowledgment->acknowledged_at?->toIso8601String(),
            'note' => $acknowledgment->note,
        ], 'Terima kasih, hasil supervisi sudah Anda tandai.');
    }

    /** Ajukan keberatan atas hasil yang sudah difinalkan. */
    public function dispute(Request $request, SupVisit $visit): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanAcknowledge($actor, $visit);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'proposed_scores' => ['nullable', 'array'],
            'proposed_scores.*.indicator_id' => ['required_with:proposed_scores', 'integer'],
            'proposed_scores.*.score' => ['required_with:proposed_scores', 'numeric'],
            'proposed_scores.*.reason' => ['nullable', 'string', 'max:500'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['string', 'max:500'],
        ]);

        $dispute = $this->acknowledgments->dispute($visit, $this->employee($actor), $data);

        return $this->createdResponse([
            'id' => (int) $dispute->id,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
        ], 'Keberatan tercatat dan menunggu keputusan moderator');
    }

    /** Putuskan keberatan (moderator / pemegang izin). */
    public function resolve(Request $request, SupDispute $dispute): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSup($actor->can('supervisi.dispute.resolve'), 'Anda tidak berwenang memutuskan keberatan.');

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', VisitAcknowledgmentService::DISPUTE_STATUSES)],
            'decision_note' => ['required', 'string', 'max:2000'],
            'moderator_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $resolved = $this->acknowledgments->resolve($dispute, $actor, $data);

        return $this->successResponse([
            'id' => (int) $resolved->id,
            'status' => $resolved->status,
            'decided_at' => $resolved->decided_at?->toIso8601String(),
            'decision_note' => $resolved->decision_note,
        ], 'Keputusan keberatan tersimpan');
    }

    protected function employee(\App\Models\User $actor): Employee
    {
        $employeeId = $this->access->employeeIdFor($actor);
        $employee = $employeeId === null ? null : Employee::query()->find($employeeId);

        if ($employee === null) {
            abort(422, 'Akun ini tidak terhubung ke data pegawai.');
        }

        return $employee;
    }
}
