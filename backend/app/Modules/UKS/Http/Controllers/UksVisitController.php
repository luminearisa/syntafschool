<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\UksAccessService;
use App\Modules\UKS\Application\Services\VisitService;
use App\Modules\UKS\Domain\Enums\Severity;
use App\Modules\UKS\Domain\Enums\VisitStatus;
use App\Modules\UKS\Domain\Enums\VisitType;
use App\Modules\UKS\Models\UksVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UksVisitController extends UksController
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly UksAccessService $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VisitStatus::class)],
            'severity' => ['nullable', Rule::enum(Severity::class)],
            'visit_type' => ['nullable', Rule::enum(VisitType::class)],
            'student_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'open_only' => ['nullable', 'boolean'],
        ]);

        $page = $this->visits->list($request->user(), $filters, $this->perPage($request));

        return $this->successResponse($page, 'Daftar kunjungan UKS.');
    }

    public function show(Request $request, UksVisit $visit): JsonResponse
    {
        return $this->successResponse(
            $this->present($this->visits->detail($request->user(), $visit)),
            'Detail kunjungan UKS.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'visit_type' => ['nullable', Rule::enum(VisitType::class)],
            'complaint' => ['required', 'string', 'max:255'],
            'symptoms' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', Rule::enum(Severity::class)],
            'arrived_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $visit = $this->visits->create($data, $request->user());

        return $this->createdResponse($this->present($visit), 'Kunjungan UKS dicatat.');
    }

    public function handle(Request $request, UksVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $data = $request->validate([
            'treatment' => ['nullable', 'string', 'max:2000'],
            'advice' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::enum(VisitStatus::class)],
            'needs_rest' => ['nullable', 'boolean'],
            'sent_home' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->present($this->visits->handle($visit, $data, $request->user())),
            'Penanganan kunjungan disimpan.',
        );
    }

    public function medication(Request $request, UksVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:uks_medicines,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'lte:1000'],
            'dosage' => ['nullable', 'string', 'max:80'],
        ]);

        $row = $this->visits->giveMedication($visit, $data, $request->user());

        return $this->createdResponse($row, 'Obat diberikan dan stok diperbarui.');
    }

    public function refer(Request $request, UksVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        $data = $request->validate([
            'facility_name' => ['required', 'string', 'max:150'],
            'facility_type' => ['nullable', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->createdResponse($this->visits->refer($visit, $data, $request->user()), 'Rujukan dicatat.');
    }

    public function consent(Request $request, UksVisit $visit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:DISETUJUI,DITOLAK'],
            'note' => ['nullable', 'string', 'max:1000'],
            'channel' => ['nullable', 'in:APLIKASI,TELEPON,TATAP_MUKA'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
        ]);

        return $this->createdResponse($this->visits->consent($visit, $data, $request->user()), 'Persetujuan dicatat.');
    }

    public function notifyParent(Request $request, UksVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        return $this->successResponse(
            $this->present($this->visits->markParentNotified($visit, $request->user())),
            'Orang tua ditandai sudah diberitahu.',
        );
    }

    public function close(Request $request, UksVisit $visit): JsonResponse
    {
        $this->access->assertCanSeeVisit($request->user(), $visit);

        return $this->successResponse(
            $this->present($this->visits->close($visit, $request->user())),
            'Kunjungan ditutup.',
        );
    }

    /** @return array<string, mixed> */
    private function present(UksVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'code' => $visit->code,
            'student_id' => $visit->student_id,
            'student' => $visit->relationLoaded('student') && $visit->student ? [
                'id' => $visit->student->id,
                'full_name' => $visit->student->full_name,
                'nis' => $visit->student->nis,
            ] : null,
            'school_class_id' => $visit->school_class_id,
            'class_name' => $visit->relationLoaded('schoolClass') ? $visit->schoolClass?->name : null,
            'visit_type' => $visit->visit_type->value,
            'visit_type_label' => $visit->visit_type->label(),
            'complaint' => $visit->complaint,
            'symptoms' => $visit->symptoms,
            'severity' => $visit->severity->value,
            'severity_label' => $visit->severity->label(),
            'status' => $visit->status->value,
            'status_label' => $visit->status->label(),
            'arrived_at' => $visit->arrived_at?->toDateTimeString(),
            'left_at' => $visit->left_at?->toDateTimeString(),
            'treatment' => $visit->treatment,
            'advice' => $visit->advice,
            'needs_rest' => $visit->needs_rest,
            'sent_home' => $visit->sent_home,
            'parent_notified' => $visit->parent_notified,
            'parent_notified_at' => $visit->parent_notified_at?->toDateTimeString(),
            'notes' => $visit->notes,
            'reported_by' => $visit->relationLoaded('reportedBy') ? $visit->reportedBy?->name : null,
            'handled_by' => $visit->relationLoaded('handledBy') ? $visit->handledBy?->name : null,
            'medications' => $visit->relationLoaded('medications') ? $visit->medications : null,
            'consents' => $visit->relationLoaded('consents') ? $visit->consents : null,
            'referral' => $visit->relationLoaded('referral') ? $visit->referral : null,
            'letters' => $visit->relationLoaded('letters') ? $visit->letters : null,
        ];
    }
}
