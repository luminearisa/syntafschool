<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Student\GuardianRequest;
use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A3 — Data Wali Murid.
 */
class GuardianController extends BaseApiController
{
    /**
     * @var list<string>
     */
    protected array $filterable = [
        'gender_id', 'religion_id', 'occupation_id', 'income_range_id', 'education_background_id',
    ];

    /**
     * @var list<string>
     */
    protected array $with = ['gender', 'religion', 'occupation', 'incomeRange', 'educationBackground'];

    public function index(Request $request): JsonResponse
    {
        $query = Guardian::query()
            ->with($this->with)
            ->withCount('students');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        // Wali dari siswa tertentu.
        if ($studentId = $request->query('student_id')) {
            $query->whereHas('students', fn ($q) => $q->whereKey((int) $studentId));
        }

        // Wali yang belum terhubung ke siswa mana pun.
        if ($request->boolean('unlinked')) {
            $query->doesntHave('students');
        }

        $query->orderBy('full_name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                GuardianResource::collection($query->get()),
                'Data wali murid berhasil diambil',
            );
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(
            GuardianResource::collection($paginator),
            'Data wali murid berhasil diambil',
        );
    }

    public function show(Guardian $guardian): JsonResponse
    {
        $guardian->load($this->with)
            ->load('students')
            ->loadCount('students');

        return $this->successResponse(new GuardianResource($guardian), 'Detail wali murid berhasil diambil');
    }

    public function store(GuardianRequest $request): JsonResponse
    {
        $guardian = Guardian::create($this->payload($request));

        return $this->createdResponse(
            new GuardianResource($guardian->load($this->with)),
            'Wali murid berhasil dibuat',
        );
    }

    public function update(GuardianRequest $request, Guardian $guardian): JsonResponse
    {
        $guardian->update($this->payload($request));

        return $this->successResponse(
            new GuardianResource($guardian->refresh()->load($this->with)),
            'Wali murid berhasil diperbarui',
        );
    }

    public function destroy(Guardian $guardian): JsonResponse
    {
        $linked = $guardian->students()->count();

        if ($linked > 0) {
            return $this->errorResponse(
                "Wali ini masih terhubung dengan {$linked} siswa. Lepas relasinya terlebih dahulu.",
                422,
            );
        }

        $guardian->delete();

        return $this->successResponse(null, 'Wali murid berhasil dihapus');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(GuardianRequest $request): array
    {
        return [
            'full_name' => $request->validated('full_name'),
            'gender_id' => $request->validated('gender_id'),
            'religion_id' => $request->validated('religion_id'),
            'address' => $request->validated('address'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'occupation_id' => $request->validated('occupation_id'),
            'income_range_id' => $request->validated('income_range_id'),
            'education_background_id' => $request->validated('education_background_id'),
        ];
    }
}
