<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingAttendanceBulkRequest;
use App\Http\Requests\Boarding\BoardingAttendanceReviseRequest;
use App\Http\Requests\Boarding\BoardingAttendanceScanRequest;
use App\Http\Requests\Boarding\BoardingAttendanceStoreRequest;
use App\Http\Resources\BoardingAttendanceResource;
use App\Models\BoardingAttendance;
use App\Models\Student;
use App\Services\Boarding\BoardingAttendanceService;
use App\Services\Boarding\BoardingScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Absensi kegiatan (§17, §23–§26): manual, massal "Hadir Semua", scan RFID
 * (kartu Wallet yang sudah ada — tanpa sistem kartu baru), koreksi ber-audit,
 * roster, riwayat dan rekap.
 */
class BoardingAttendanceController extends BaseApiController
{
    public function __construct(
        private readonly BoardingAttendanceService $attendance,
        private readonly BoardingScopeService $scope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->attendance->query($request->all(), $request->user());

        if ($request->input('all')) {
            return $this->successResponse(BoardingAttendanceResource::collection($query->get()), 'Daftar absensi berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingAttendanceResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar absensi berhasil diambil',
        );
    }

    /** Layar absensi: daftar target + catatan yang sudah ada (§24). */
    public function roster(Request $request): JsonResponse
    {
        $params = $request->validate([
            'activity_id' => ['required', 'integer', 'exists:boarding_activities,id'],
            'date' => ['nullable', 'date'],
            'session' => ['nullable', 'string', 'max:20'],
            'group_id' => ['nullable', 'integer', 'exists:boarding_groups,id'],
            'room_id' => ['nullable', 'integer', 'exists:boarding_rooms,id'],
            'dormitory_id' => ['nullable', 'integer', 'exists:boarding_dormitories,id'],
        ]);

        return $this->successResponse(
            $this->attendance->roster($request->user(), $params),
            'Daftar sasaran absensi berhasil diambil',
        );
    }

    /** Rekap kehadiran per santri untuk satu rentang tanggal (§43). */
    public function recap(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'activity_id' => ['nullable', 'integer', 'exists:boarding_activities,id'],
            'group_id' => ['nullable', 'integer', 'exists:boarding_groups,id'],
            'room_id' => ['nullable', 'integer', 'exists:boarding_rooms,id'],
            'dormitory_id' => ['nullable', 'integer', 'exists:boarding_dormitories,id'],
        ]);

        return $this->successResponse(
            $this->attendance->recap($params, $request->user()),
            'Rekap kehadiran berhasil diambil',
        );
    }

    /** Pantauan kelengkapan pengisian absensi per asrama untuk satu tanggal. */
    public function monitoring(Request $request): JsonResponse
    {
        $params = $request->validate([
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}$/'],
            'activity_id' => ['nullable', 'integer', 'exists:boarding_activities,id'],
            'dormitory_id' => ['nullable', 'integer', 'exists:boarding_dormitories,id'],
        ]);

        return $this->successResponse(
            $this->attendance->monitoring($params, $request->user()),
            'Pantauan absensi berhasil diambil',
        );
    }

    /** Agregat absensi untuk grafik dasbor (donat, tren harian, per asrama). */
    public function statistics(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'activity_id' => ['nullable', 'integer', 'exists:boarding_activities,id'],
            'dormitory_id' => ['nullable', 'integer', 'exists:boarding_dormitories,id'],
        ]);

        return $this->successResponse(
            $this->attendance->statistics($params, $request->user()),
            'Statistik absensi berhasil diambil',
        );
    }

    public function show(BoardingAttendance $attendance): JsonResponse
    {
        $attendance->load($this->attendance->eagerLoads());
        $this->scope->assertCanAccessStudent(request()->user(), (int) $attendance->student_id);

        return $this->successResponse(new BoardingAttendanceResource($attendance), 'Detail absensi berhasil diambil');
    }

    public function store(BoardingAttendanceStoreRequest $request): JsonResponse
    {
        $record = $this->attendance->store($request->validated(), $request->user());

        return $this->createdResponse(new BoardingAttendanceResource($record), 'Absensi berhasil dicatat');
    }

    /** "Hadir Semua" / simpan seluruh roster sekaligus (§24). */
    public function bulk(BoardingAttendanceBulkRequest $request): JsonResponse
    {
        $result = $this->attendance->bulk($request->validated(), $request->user());

        return $this->createdResponse(
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'total' => $result['total'],
                'records' => BoardingAttendanceResource::collection($result['records'])->resolve(request()),
            ],
            sprintf(
                'Absensi massal tersimpan: %d dibuat, %d diperbarui, %d tidak berubah.',
                $result['created'],
                $result['updated'],
                $result['skipped'],
            ),
        );
    }

    /** Koreksi — nilai berubah, riwayat tersimpan (§26). */
    public function revise(BoardingAttendanceReviseRequest $request, BoardingAttendance $attendance): JsonResponse
    {
        $record = $this->attendance->revise($attendance, $request->validated(), $request->user());

        return $this->successResponse(new BoardingAttendanceResource($record), 'Absensi berhasil dikoreksi');
    }

    /** Scan kartu santri (§23, §25) — satu endpoint untuk reader RFID maupun kamera QR. */
    public function scan(BoardingAttendanceScanRequest $request): JsonResponse
    {
        $result = strval($request->input('code') ?? '') !== ''
            ? $this->attendance->scanQr(strval($request->input('code')), $request->validated(), $request->user())
            : $this->attendance->scan(strval($request->input('uid')), $request->validated(), $request->user());

        $status = ($result['duplicate'] ?? false) ? 200 : 201;

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Absensi RFID berhasil dicatat',
            'data' => [
                'attendance' => ($result['attendance'] ?? null) === null
                    ? null
                    : (new BoardingAttendanceResource($result['attendance']))->resolve(request()),
                'student' => $result['student'] ?? null,
                'activity' => $result['activity'] ?? null,
                'card' => $result['card'] ?? null,
                'already_recorded' => (bool) ($result['already_recorded'] ?? false),
            ],
        ], $status);
    }

    /** Riwayat absensi satu santri (§kartu santri & portal orang tua). */
    public function history(Request $request, Student $student): JsonResponse
    {
        $query = $this->attendance->history($student, $request->all(), $request->user());

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingAttendanceResource::collection($query->paginate($perPage)->appends($request->query())),
            'Riwayat absensi berhasil diambil',
        );
    }
}
