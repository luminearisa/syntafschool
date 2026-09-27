<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingActivityRequest;
use App\Http\Resources\BoardingActivityResource;
use App\Models\BoardingActivity;
use App\Models\BoardingAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kegiatan asrama (§16) dan daftar tipe absensi yang boleh dipakai (§18).
 */
class BoardingActivityController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingActivity::query()
            ->with(['defaultAttendanceType:id,code,name,color'])
            ->with('attendanceTypes:id,code,name,category,color,is_present')
            ->withCount('schedules')
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->string('category')->toString()))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->has('is_required'), fn (Builder $q) => $q->where('is_required', $request->boolean('is_required')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $w) => $w
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('id');

        if ($request->input('all')) {
            return $this->successResponse(BoardingActivityResource::collection($query->get()), 'Daftar kegiatan berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingActivityResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kegiatan berhasil diambil',
        );
    }

    public function show(BoardingActivity $activity): JsonResponse
    {
        $activity->load([
            'defaultAttendanceType:id,code,name,category,color,is_present',
            'attendanceTypes:id,code,name,category,color,is_present',
        ])->loadCount('schedules');

        return $this->successResponse(new BoardingActivityResource($activity), 'Detail kegiatan berhasil diambil');
    }

    public function store(BoardingActivityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $allowed = $data['allowed_attendance_type_ids'] ?? null;
        unset($data['allowed_attendance_type_ids']);

        $activity = DB::transaction(function () use ($data, $allowed): BoardingActivity {
            $activity = BoardingActivity::create($data);
            $this->syncTypes($activity, $allowed);

            return $activity;
        });

        return $this->createdResponse(new BoardingActivityResource($this->load($activity)), 'Kegiatan berhasil dibuat');
    }

    public function update(BoardingActivityRequest $request, BoardingActivity $activity): JsonResponse
    {
        $data = $request->validated();
        $allowed = $data['allowed_attendance_type_ids'] ?? null;
        unset($data['allowed_attendance_type_ids']);

        DB::transaction(function () use ($activity, $data, $allowed): void {
            $activity->update($data);
            $this->syncTypes($activity, $allowed);
        });

        return $this->successResponse(new BoardingActivityResource($this->load($activity->refresh())), 'Kegiatan berhasil diperbarui');
    }

    public function destroy(BoardingActivity $activity): JsonResponse
    {
        $used = BoardingAttendance::query()->where('activity_id', $activity->id)->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'activity' => "Kegiatan ini punya {$used} catatan absensi. Nonaktifkan (is_active = false) agar riwayat tetap terbaca.",
            ]);
        }

        $activity->delete();

        return $this->successResponse(null, 'Kegiatan berhasil dihapus');
    }

    /** @param list<int>|null $allowed */
    private function syncTypes(BoardingActivity $activity, ?array $allowed): void
    {
        if ($allowed === null) {
            return;
        }

        $activity->attendanceTypes()->sync(
            collect(array_values(array_unique(array_map('intval', $allowed))))
                ->mapWithKeys(fn (int $id, int $i): array => [$id => ['sort_order' => $i]])
                ->all()
        );
    }

    private function load(BoardingActivity $activity): BoardingActivity
    {
        return $activity->load([
            'defaultAttendanceType:id,code,name,category,color,is_present',
            'attendanceTypes:id,code,name,category,color,is_present',
        ])->loadCount('schedules');
    }
}
