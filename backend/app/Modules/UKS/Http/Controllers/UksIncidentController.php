<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\IncidentService;
use App\Modules\UKS\Application\Services\UksAccessService;
use App\Modules\UKS\Domain\Enums\IncidentStatus;
use App\Modules\UKS\Domain\Enums\Severity;
use App\Modules\UKS\Models\UksIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UksIncidentController extends UksController
{
    public function __construct(
        private readonly IncidentService $incidents,
        private readonly UksAccessService $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(IncidentStatus::class)],
            'severity' => ['nullable', Rule::enum(Severity::class)],
            'student_id' => ['nullable', 'integer'],
        ]);

        return $this->successResponse(
            $this->incidents->list($request->user(), $filters, $this->perPage($request)),
            'Daftar insiden.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'visit_id' => ['nullable', 'integer', 'exists:uks_visits,id'],
            'occurred_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', Rule::enum(Severity::class)],
            'description' => ['required', 'string', 'max:2000'],
            'first_aid' => ['nullable', 'string', 'max:2000'],
            'witnesses' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->createdResponse($this->incidents->create($data, $request->user()), 'Insiden dilaporkan.');
    }

    public function update(Request $request, UksIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(IncidentStatus::class)],
            'follow_up' => ['nullable', 'string', 'max:2000'],
            'parent_notified' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(
            $this->incidents->update($incident, $data, $request->user()),
            'Insiden diperbarui.',
        );
    }
}
