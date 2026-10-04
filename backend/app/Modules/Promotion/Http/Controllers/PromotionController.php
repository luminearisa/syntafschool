<?php

namespace App\Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\User;
use App\Modules\Promotion\Application\Services\PromotionService;
use App\Modules\Promotion\Http\Requests\ApprovePromotionDecisionRequest;
use App\Modules\Promotion\Http\Requests\BulkStorePromotionDecisionRequest;
use App\Modules\Promotion\Http\Requests\EvaluatePromotionRequest;
use App\Modules\Promotion\Http\Requests\ReopenPromotionDecisionRequest;
use App\Modules\Promotion\Http\Requests\UpdatePromotionDecisionRequest;
use App\Modules\Promotion\Http\Resources\PromotionDecisionResource;
use App\Modules\Promotion\Http\Resources\PromotionRuleResource;
use App\Modules\Promotion\Models\PromotionApproval;
use App\Modules\Promotion\Models\PromotionDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends BaseApiController
{
    public function __construct(private readonly PromotionService $service) {}

    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'classes' => SchoolClass::query()
                ->where('is_active', true)
                ->with('educationLevel:id,name,level_number')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'academic_year_id', 'education_level_id']),
            'academic_years' => AcademicYear::query()->orderByDesc('start_date')->get(['id', 'name', 'is_active']),
            'education_levels' => EducationLevel::query()->orderBy('jenjang')->orderBy('level_number')->get(['id', 'name', 'jenjang', 'level_number']),
            'semesters' => Semester::query()->with('academicYear:id,name')->orderByDesc('start_date')->get(['id', 'academic_year_id', 'name', 'is_active']),
            'statuses' => PromotionDecision::STATUS_LABELS,
            'required_approvers' => PromotionApproval::REQUIRED_ROLES,
        ], 'Referensi keputusan kenaikan kelas berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = PromotionDecision::query()
            ->with(['student', 'schoolClass', 'targetClass', 'academicYear', 'approvals.approver'])
            ->when($request->filled('class_id'), fn (Builder $q) => $q->where('school_class_id', (int) $request->input('class_id')))
            ->when($request->filled('academic_year_id'), fn (Builder $q) => $q->where('academic_year_id', (int) $request->input('academic_year_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('decision'), fn (Builder $q) => $q->where('decision', $request->string('decision')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->whereHas('student', fn (Builder $student) => $student
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('nis', 'like', "%{$term}%"));
            })
            ->orderByDesc('id');

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            PromotionDecisionResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar keputusan kenaikan kelas berhasil diambil',
        );
    }

    public function evaluate(EvaluatePromotionRequest $request): JsonResponse
    {
        $class = SchoolClass::query()->findOrFail((int) $request->validated('class_id'));

        $result = $this->service->evaluate(
            $class,
            $request->validated('semester_id') === null ? null : (int) $request->validated('semester_id'),
        );

        // Bungkus aturan sebagai resource agar bentuknya konsisten dengan endpoint lain.
        $result['rule'] = $result['rule'] === null ? null : new PromotionRuleResource($result['rule']);

        return $this->successResponse($result, 'Pratinjau keputusan kenaikan kelas berhasil dihitung');
    }

    public function bulk(BulkStorePromotionDecisionRequest $request): JsonResponse
    {
        $class = SchoolClass::query()->findOrFail((int) $request->validated('class_id'));

        $saved = $this->service->bulk(
            $class,
            $request->validated('decisions'),
            $request->user()?->id,
        );

        return $this->createdResponse(
            PromotionDecisionResource::collection($saved),
            'Keputusan kenaikan kelas berhasil disimpan sebagai draft',
        );
    }

    public function show(PromotionDecision $decision): JsonResponse
    {
        $decision->load(['student', 'schoolClass', 'targetClass', 'academicYear', 'approvals.approver']);

        return $this->successResponse(new PromotionDecisionResource($decision), 'Detail keputusan berhasil diambil');
    }

    public function update(UpdatePromotionDecisionRequest $request, PromotionDecision $decision): JsonResponse
    {
        $updated = $this->service->update($decision, $request->validated());

        return $this->successResponse(new PromotionDecisionResource($updated), 'Keputusan berhasil diperbarui');
    }

    public function submit(PromotionDecision $decision): JsonResponse
    {
        $updated = $this->service->submit($decision);

        return $this->successResponse(new PromotionDecisionResource($updated), 'Keputusan diajukan untuk persetujuan');
    }

    public function approve(ApprovePromotionDecisionRequest $request, PromotionDecision $decision): JsonResponse
    {
        $role = $this->resolveApproverRole($request->user(), $request->validated('approver_role'));

        $updated = $this->service->approve(
            $decision,
            $role,
            $request->user()?->id,
            $request->validated('note'),
        );

        $message = $updated->status === PromotionDecision::STATUS_APPROVED
            ? 'Keputusan berhasil disetujui sepenuhnya'
            : 'Persetujuan dicatat, menunggu penyetuju lain';

        return $this->successResponse(new PromotionDecisionResource($updated), $message);
    }

    public function finalize(Request $request, PromotionDecision $decision): JsonResponse
    {
        $updated = $this->service->finalize($decision, $request->user()?->id);

        return $this->successResponse(new PromotionDecisionResource($updated), 'Keputusan berhasil difinalisasi');
    }

    public function reopen(ReopenPromotionDecisionRequest $request, PromotionDecision $decision): JsonResponse
    {
        $updated = $this->service->reopen($decision, (string) $request->validated('reason'));

        return $this->successResponse(new PromotionDecisionResource($updated), 'Keputusan dibuka ulang untuk ditinjau');
    }

    public function destroy(PromotionDecision $decision): JsonResponse
    {
        if ($decision->isFinalized()) {
            return $this->errorResponse('Keputusan yang sudah difinalisasi tidak dapat dihapus. Batalkan terlebih dahulu.', 422);
        }

        $decision->delete();

        return $this->successResponse(null, 'Keputusan berhasil dihapus');
    }

    /**
     * Peran penyetuju: pakai yang dikirim klien bila ada, selain itu turunkan
     * dari role pengguna (kepala-sekolah / wali-kelas).
     */
    private function resolveApproverRole(?User $user, ?string $requested): string
    {
        if ($requested !== null) {
            return $requested;
        }

        $map = [
            'wali_kelas' => PromotionApproval::ROLE_WALI_KELAS,
            'wali-kelas' => PromotionApproval::ROLE_WALI_KELAS,
            'kepala_sekolah' => PromotionApproval::ROLE_KEPALA_SEKOLAH,
            'kepala-sekolah' => PromotionApproval::ROLE_KEPALA_SEKOLAH,
        ];

        foreach (($user?->getRoleNames() ?? []) as $name) {
            if (isset($map[$name])) {
                return $map[$name];
            }
        }

        return PromotionApproval::ROLE_KEPALA_SEKOLAH;
    }
}
