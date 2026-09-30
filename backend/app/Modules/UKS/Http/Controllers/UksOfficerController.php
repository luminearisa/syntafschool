<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Models\Employee;
use App\Modules\UKS\Domain\Enums\OfficerRole;
use App\Modules\UKS\Models\UksOfficer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UksOfficerController extends UksController
{
    public function index(Request $request): JsonResponse
    {
        $officers = UksOfficer::query()
            ->with('employee:id,full_name,nip,position,employee_type')
            ->orderByDesc('is_active')
            ->orderBy('role')
            ->paginate($this->perPage($request));

        return $this->successResponse($officers, 'Daftar petugas UKS.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id', 'unique:uks_officers,employee_id'],
            'role' => ['required', Rule::enum(OfficerRole::class)],
            'license_no' => ['nullable', 'string', 'max:60'],
            'appointed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $officer = UksOfficer::query()->create($data + ['is_active' => true]);

        return $this->createdResponse($officer->load('employee:id,full_name'), 'Petugas UKS ditambahkan.');
    }

    public function update(Request $request, UksOfficer $officer): JsonResponse
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::enum(OfficerRole::class)],
            'license_no' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $officer->fill($data)->save();

        return $this->successResponse($officer->refresh(), 'Petugas UKS diperbarui.');
    }

    /** Pegawai yang belum terdaftar sebagai petugas (untuk pemilih). */
    public function candidates(Request $request): JsonResponse
    {
        $taken = UksOfficer::query()->pluck('employee_id');

        $query = Employee::query()
            ->where('is_active', true)
            ->whereNotIn('id', $taken)
            ->select('id', 'full_name', 'nip', 'position', 'employee_type')
            ->orderBy('full_name');

        if ($search = trim((string) $request->string('search'))) {
            $safe = '%'.addcslashes(mb_substr($search, 0, 60), '%_\\').'%';
            $query->where(fn ($q) => $q->where('full_name', 'like', $safe)->orWhere('nip', 'like', $safe));
        }

        return $this->successResponse($query->limit(30)->get(), 'Calon petugas UKS.');
    }
}
