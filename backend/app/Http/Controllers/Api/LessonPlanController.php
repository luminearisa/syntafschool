<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Teaching\LessonPlanRequest;
use App\Http\Requests\Teaching\RejectLessonPlanRequest;
use App\Http\Resources\LessonPlanResource;
use App\Models\LessonPlan;
use App\Traits\ResolvesTeachingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * RPP / modul ajar.
 *
 * Alur status: `draft → submitted → approved | rejected`, dan `rejected → draft`
 * ketika guru merevisi isinya. Isi RPP hanya boleh diubah saat `draft`/`rejected`;
 * selebihnya harus dikembalikan lewat penolakan.
 */
class LessonPlanController extends BaseApiController
{
    use ResolvesTeachingContext;

    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'teacher_id',
        'subject_id',
        'class_id',
        'semester_id',
        'status',
    ];

    /** Relasi standar agar frontend tidak perlu request tambahan. */
    protected array $with = [
        'teacher',
        'subject',
        'educationLevel',
        'schoolClass',
        'semester',
        'approver',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = LessonPlan::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                LessonPlanResource::collection($query->get()),
                'Daftar RPP berhasil diambil',
            );
        }

        return $this->successResponse(
            LessonPlanResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar RPP berhasil diambil',
        );
    }

    public function store(LessonPlanRequest $request): JsonResponse
    {
        $data = $request->validated();

        $teacherId = $this->resolveTeacherId($request);

        if (! $teacherId) {
            throw ValidationException::withMessages([
                'teacher_id' => 'Guru penyusun wajib dipilih.',
            ]);
        }

        $this->assertTeacherOwnership($request, $teacherId);

        $data['teacher_id'] = $teacherId;
        $data['status'] = LessonPlan::STATUS_DRAFT;
        $data['created_by'] = $request->user()?->id;

        $plan = LessonPlan::create($data);

        return $this->createdResponse(
            new LessonPlanResource($plan->load($this->with)),
            'RPP berhasil dibuat',
        );
    }

    public function show(LessonPlan $plan): JsonResponse
    {
        return $this->successResponse(
            new LessonPlanResource($plan->load($this->with)),
            'Detail RPP berhasil diambil',
        );
    }

    public function update(LessonPlanRequest $request, LessonPlan $plan): JsonResponse
    {
        $this->assertCanManage($request, $plan);
        $this->assertEditable($plan);

        $data = $request->validated();

        $teacherId = $data['teacher_id'] ?? (int) $plan->teacher_id;
        $this->assertTeacherOwnership($request, (int) $teacherId);
        $data['teacher_id'] = $teacherId;

        // Revisi RPP yang ditolak mengembalikannya ke draft.
        if ($plan->status === LessonPlan::STATUS_REJECTED) {
            $data['status'] = LessonPlan::STATUS_DRAFT;
            $data['rejection_reason'] = null;
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }

        $plan->update($data);

        return $this->successResponse(
            new LessonPlanResource($plan->refresh()->load($this->with)),
            'RPP berhasil diperbarui',
        );
    }

    public function destroy(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->assertCanManage($request, $plan);

        $plan->delete();

        return $this->successResponse(null, 'RPP berhasil dihapus');
    }

    /**
     * Ajukan RPP untuk disetujui. Hanya berlaku untuk status `draft`.
     */
    public function submit(Request $request, LessonPlan $plan): JsonResponse
    {
        $this->assertCanManage($request, $plan);

        if ($plan->status !== LessonPlan::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Hanya RPP berstatus draft yang bisa diajukan.',
            ]);
        }

        $plan->update([
            'status' => LessonPlan::STATUS_SUBMITTED,
            'rejection_reason' => null,
        ]);

        return $this->successResponse(
            new LessonPlanResource($plan->refresh()->load($this->with)),
            'RPP berhasil diajukan untuk disetujui',
        );
    }

    /**
     * Setujui RPP yang sudah diajukan.
     */
    public function approve(Request $request, LessonPlan $plan): JsonResponse
    {
        if ($plan->status !== LessonPlan::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Hanya RPP yang sudah diajukan yang bisa disetujui.',
            ]);
        }

        $plan->update([
            'status' => LessonPlan::STATUS_APPROVED,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return $this->successResponse(
            new LessonPlanResource($plan->refresh()->load($this->with)),
            'RPP berhasil disetujui',
        );
    }

    /**
     * Tolak RPP yang sudah diajukan — alasan wajib diisi.
     */
    public function reject(RejectLessonPlanRequest $request, LessonPlan $plan): JsonResponse
    {
        if ($plan->status !== LessonPlan::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Hanya RPP yang sudah diajukan yang bisa ditolak.',
            ]);
        }

        $plan->update([
            'status' => LessonPlan::STATUS_REJECTED,
            'rejection_reason' => $request->validated()['rejection_reason'],
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $this->successResponse(
            new LessonPlanResource($plan->refresh()->load($this->with)),
            'RPP ditolak dan dikembalikan ke guru',
        );
    }

    /**
     * Guru pemilik boleh mengelola RPP-nya; akun tanpa tautan pegawai (admin)
     * bebas mengelola seluruh RPP.
     */
    protected function assertCanManage(Request $request, LessonPlan $plan): void
    {
        $linked = $this->linkedEmployeeId($request->user());

        if ($linked !== null && $linked !== (int) $plan->teacher_id) {
            abort(403, 'Anda hanya boleh mengubah RPP milik sendiri.');
        }
    }

    protected function assertEditable(LessonPlan $plan): void
    {
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Isi RPP hanya bisa diubah saat berstatus draft atau ditolak.',
            ]);
        }
    }
}
