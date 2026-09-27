<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingAttendanceTypeRequest;
use App\Http\Resources\BoardingAttendanceTypeResource;
use App\Models\BoardingAttendance;
use App\Models\BoardingAttendanceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tipe absensi (§16) — seluruh status hadir/izin/sakit/alpha datang dari sini,
 * tidak ada yang di-hard-code di frontend maupun service.
 */
class BoardingAttendanceTypeController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingAttendanceType::query()
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->string('category')->toString()))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $w) => $w
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('id');

        if ($request->input('all')) {
            return $this->successResponse(BoardingAttendanceTypeResource::collection($query->get()), 'Daftar tipe absensi berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingAttendanceTypeResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar tipe absensi berhasil diambil',
        );
    }

    public function show(BoardingAttendanceType $attendanceType): JsonResponse
    {
        return $this->successResponse(new BoardingAttendanceTypeResource($attendanceType), 'Detail tipe absensi berhasil diambil');
    }

    public function store(BoardingAttendanceTypeRequest $request): JsonResponse
    {
        $type = BoardingAttendanceType::create($request->validated());

        return $this->createdResponse(new BoardingAttendanceTypeResource($type), 'Tipe absensi berhasil dibuat');
    }

    public function update(BoardingAttendanceTypeRequest $request, BoardingAttendanceType $attendanceType): JsonResponse
    {
        $attendanceType->update($request->validated());

        return $this->successResponse(
            new BoardingAttendanceTypeResource($attendanceType->refresh()),
            'Tipe absensi berhasil diperbarui',
        );
    }

    /** Absensi yang menunjuk tipe ini menolak penghapusan (FK RESTRICT). */
    public function destroy(BoardingAttendanceType $attendanceType): JsonResponse
    {
        $used = BoardingAttendance::query()->where('attendance_type_id', $attendanceType->id)->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'attendance_type' => "Tipe ini dipakai {$used} catatan absensi. Nonaktifkan saja (is_active = false), riwayat tidak boleh dihapus.",
            ]);
        }

        $attendanceType->delete();

        return $this->successResponse(null, 'Tipe absensi berhasil dihapus');
    }
}
