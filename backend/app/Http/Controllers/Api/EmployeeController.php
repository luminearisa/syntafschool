<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Employee\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends BaseApiController
{
    /**
     * Kolom yang boleh dipakai untuk filter.
     *
     * @var list<string>
     */
    protected array $filterable = ['school_id', 'employee_type', 'employment_status', 'is_teacher', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->with(['gender', 'religion', 'school'])
            ->withCount(['homeroomClasses', 'classSubjects']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $this->filterable, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        if ($request->boolean('teachers_only')) {
            $query->where('is_teacher', true);
        }

        $query->orderBy('full_name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                EmployeeResource::collection($query->get()),
                'Data pegawai berhasil diambil',
            );
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(
            EmployeeResource::collection($paginator),
            'Data pegawai berhasil diambil',
        );
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load(['gender', 'religion', 'school', 'user'])
            ->loadCount(['homeroomClasses', 'classSubjects']);

        return $this->successResponse(new EmployeeResource($employee), 'Detail pegawai berhasil diambil');
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = Employee::create($this->payload($request));

        return $this->createdResponse(
            new EmployeeResource($employee->load(['gender', 'religion', 'school'])),
            'Pegawai berhasil dibuat',
        );
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee->update($this->payload($request));

        return $this->successResponse(
            new EmployeeResource($employee->refresh()->load(['gender', 'religion', 'school'])),
            'Pegawai berhasil diperbarui',
        );
    }

    public function destroy(Employee $employee): JsonResponse
    {
        if ($employee->homeroomClasses()->exists()) {
            return $this->errorResponse(
                'Pegawai masih menjadi wali kelas. Ganti wali kelas terlebih dahulu sebelum menghapus.',
                422,
            );
        }

        $employee->delete();

        return $this->successResponse(null, 'Pegawai berhasil dihapus');
    }

    /**
     * Aktifkan / nonaktifkan pegawai.
     */
    public function toggleActive(Employee $employee): JsonResponse
    {
        $employee->update(['is_active' => ! $employee->is_active]);

        return $this->successResponse(
            new EmployeeResource($employee->refresh()->load(['gender', 'religion', 'school'])),
            'Status pegawai berhasil diubah',
        );
    }

    /**
     * Buat akun login untuk pegawai (opsional).
     */
    public function createAccount(Request $request, Employee $employee): JsonResponse
    {
        if ($employee->user()->exists()) {
            return $this->errorResponse('Pegawai ini sudah memiliki akun login.', 422);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:50', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ], [], [
            'email' => 'email',
            'username' => 'username',
            'password' => 'password',
            'role' => 'role',
        ]);

        $user = DB::transaction(function () use ($employee, $data) {
            $user = $employee->user()->create([
                'name' => $employee->full_name,
                'username' => $data['username'] ?? null,
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $employee->phone,
                'is_active' => true,
            ]);

            $user->syncRoles([$data['role']]);

            return $user;
        });

        return $this->createdResponse(
            new UserResource($user->load('roles')),
            'Akun login pegawai berhasil dibuat',
        );
    }

    /**
     * Susun atribut pegawai dari request.
     *
     * Pegawai berjenis "guru" selalu ditandai sebagai pengajar; jenis "staf"
     * mengikuti nilai is_teacher yang dikirim (default false).
     *
     * @return array<string, mixed>
     */
    protected function payload(EmployeeRequest $request): array
    {
        $type = $request->validated('employee_type');

        return [
            'school_id' => $request->validated('school_id'),
            'nip' => $request->validated('nip'),
            'full_name' => $request->validated('full_name'),
            'gender_id' => $request->validated('gender_id'),
            'religion_id' => $request->validated('religion_id'),
            'birth_place' => $request->validated('birth_place'),
            'birth_date' => $request->validated('birth_date'),
            'address' => $request->validated('address'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'position' => $request->validated('position'),
            'employee_type' => $type,
            'employment_status' => $request->validated('employment_status'),
            'join_date' => $request->validated('join_date'),
            'is_teacher' => $type === 'guru' ? true : $request->boolean('is_teacher'),
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
