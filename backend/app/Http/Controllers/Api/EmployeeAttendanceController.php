<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Attendance\EmployeeAttendanceRequest;
use App\Http\Resources\EmployeeAttendanceResource;
use App\Models\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeaveType;
use App\Models\Semester;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Presensi pegawai (guru & staf).
 *
 * Satu pegawai hanya punya satu baris presensi per tanggal; input ulang
 * memperbarui baris yang sama (upsert) supaya jam pulang bisa diisi belakangan.
 */
class EmployeeAttendanceController extends BaseApiController
{
    /** Kolom yang boleh difilter lewat query string. */
    protected array $filterable = [
        'employee_id',
        'attendance_status_id',
    ];

    protected array $with = ['employee', 'leaveType'];

    /** Lookups presensi yang dapat dibaca petugas kepegawaian maupun akademik. */
    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'statuses' => AttendanceStatus::query()->orderBy('id')->get(['id', 'code', 'name']),
            'leave_types' => LeaveType::query()->orderBy('name')->get(['id', 'name', 'requires_attachment']),
            'semesters' => Semester::query()->with('academicYear:id,name')->orderByDesc('start_date')->get(['id', 'academic_year_id', 'name', 'start_date', 'end_date', 'is_active']),
        ], 'Referensi presensi berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = EmployeeAttendance::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('date')->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                EmployeeAttendanceResource::collection($query->get()),
                'Daftar presensi pegawai berhasil diambil',
            );
        }

        return $this->successResponse(
            EmployeeAttendanceResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar presensi pegawai berhasil diambil',
        );
    }

    public function show(EmployeeAttendance $employeeAttendance): JsonResponse
    {
        return $this->successResponse(
            new EmployeeAttendanceResource($employeeAttendance->load($this->with)),
            'Detail presensi pegawai berhasil diambil',
        );
    }

    public function store(EmployeeAttendanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertLeaveType($data);

        $data['recorded_by'] = $request->user()?->id;

        // Upsert: satu pegawai satu baris per tanggal.
        $existing = EmployeeAttendance::query()
            ->where('employee_id', $data['employee_id'])
            ->whereDate('date', $data['date'])
            ->first();

        $payload = $this->normalizeTimes($data);

        if ($existing) {
            $existing->update($payload);

            return $this->successResponse(
                new EmployeeAttendanceResource($existing->refresh()->load($this->with)),
                'Presensi pegawai berhasil diperbarui',
            );
        }

        $attendance = EmployeeAttendance::create($payload);

        return $this->createdResponse(
            new EmployeeAttendanceResource($attendance->load($this->with)),
            'Presensi pegawai berhasil dicatat',
        );
    }

    public function update(EmployeeAttendanceRequest $request, EmployeeAttendance $employeeAttendance): JsonResponse
    {
        $data = $request->validated();

        $this->assertLeaveType($data);

        $duplicate = EmployeeAttendance::query()
            ->where('employee_id', $data['employee_id'])
            ->whereDate('date', $data['date'])
            ->where('id', '!=', $employeeAttendance->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'date' => 'Pegawai ini sudah punya presensi pada tanggal tersebut.',
            ]);
        }

        $employeeAttendance->update($this->normalizeTimes($data));

        return $this->successResponse(
            new EmployeeAttendanceResource($employeeAttendance->refresh()->load($this->with)),
            'Presensi pegawai berhasil diperbarui',
        );
    }

    public function destroy(EmployeeAttendance $employeeAttendance): JsonResponse
    {
        $employeeAttendance->delete();

        return $this->successResponse(null, 'Presensi pegawai berhasil dihapus');
    }

    /**
     * Rekap presensi pegawai: jumlah kehadiran, keterlambatan, dan izin.
     */
    public function recap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ], [], [
            'from' => 'tanggal awal',
            'to' => 'tanggal akhir',
            'employee_id' => 'pegawai',
        ]);

        $employees = Employee::query()
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('full_name')
            ->get();

        $statuses = AttendanceStatus::query()->get()->keyBy('code');

        $items = $employees->map(function (Employee $employee) use ($data, $statuses) {
            $rows = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->between($data['from'], $data['to'])
                ->with('attendanceStatus')
                ->get();

            $countByCode = $rows->groupBy(fn ($row) => $row->attendanceStatus?->code ?? '-')
                ->map(fn ($group) => $group->count());

            return [
                'employee_id' => $employee->id,
                'employee' => [
                    'id' => $employee->id,
                    'nip' => $employee->nip,
                    'full_name' => $employee->full_name,
                ],
                'total_days' => $rows->count(),
                'present' => (int) ($countByCode[AttendanceStatus::CODE_PRESENT] ?? 0),
                'late' => (int) ($countByCode[AttendanceStatus::CODE_LATE] ?? 0),
                'sick' => (int) ($countByCode[AttendanceStatus::CODE_SICK] ?? 0),
                'permitted' => (int) ($countByCode[AttendanceStatus::CODE_PERMITTED] ?? 0),
                'absent' => (int) ($countByCode[AttendanceStatus::CODE_ABSENT] ?? 0),
                'breakdown' => $countByCode,
            ];
        })->values();

        return $this->successResponse([
            'from' => $data['from'],
            'to' => $data['to'],
            'items' => $items,
            'totals' => [
                'employees' => $items->count(),
                'present' => (int) $items->sum('present'),
                'late' => (int) $items->sum('late'),
                'sick' => (int) $items->sum('sick'),
                'permitted' => (int) $items->sum('permitted'),
                'absent' => (int) $items->sum('absent'),
            ],
        ], 'Rekap presensi pegawai berhasil diambil');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeTimes(array $data): array
    {
        foreach (['check_in', 'check_out'] as $column) {
            if (! empty($data[$column])) {
                $data[$column] = Carbon::parse($data[$column])->format('H:i:s');
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertLeaveType(array $data): void
    {
        $status = AttendanceStatus::find($data['attendance_status_id'] ?? null);

        if ($status === null) {
            return;
        }

        if (in_array($status->code, AttendanceStatus::CODES_REQUIRING_LEAVE_TYPE, true)
            && empty($data['leave_type_id'])) {
            throw ValidationException::withMessages([
                'leave_type_id' => "Jenis izin wajib diisi untuk status {$status->name}.",
            ]);
        }
    }
}
