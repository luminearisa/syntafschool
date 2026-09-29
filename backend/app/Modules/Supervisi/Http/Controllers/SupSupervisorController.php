<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Models\Employee;
use App\Modules\Supervisi\Application\Services\SupervisorDirectoryService;
use App\Modules\Supervisi\Domain\Enums\InstrumentCategory;
use App\Modules\Supervisi\Domain\Enums\SupervisorKind;
use App\Modules\Supervisi\Models\SupAssignment;
use App\Modules\Supervisi\Models\SupSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan supervisor — **memilih siapa saja** yang boleh menilai/membina.
 *
 * Sekolah mendaftarkan pegawai ke registry, menentukan jenis kewenangan
 * (kepala sekolah, wakasek, koordinator, pengawas, mentor, sejawat, eksternal),
 * cakupan, kapasitas, dan hak finalisasi.
 */
class SupSupervisorController extends SupController
{
    public function __construct(private readonly SupervisorDirectoryService $directory) {}

    /** Daftar supervisor terdaftar. */
    public function index(Request $request): JsonResponse
    {
        $query = SupSupervisor::query()
            ->with('employee:id,full_name,nip,position,employee_type,photo_path,school_id')
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = (string) $request->string('search');
                $q->whereHas('employee', fn ($inner) => $inner
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%"))
                    ->orWhere('certification_no', 'like', "%{$search}%");
            })
            ->orderBy('kind')
            ->orderByDesc('is_active');

        return $this->successResponse(
            $query->paginate($this->perPage($request))->through(fn (SupSupervisor $supervisor) => $this->payload($supervisor)),
            'Daftar supervisor berhasil diambil',
        );
    }

    /**
     * Calon supervisor: pegawai aktif yang belum terdaftar, lengkap dengan
     * usulan jenis kewenangan — sekali centang untuk menambahkan.
     */
    public function candidates(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->directory->candidates($request->query('search'), (int) $request->query('limit', 50)),
            'Calon supervisor berhasil diambil',
        );
    }

    /** Matriks “siapa menilai siapa” beserta peringatan tata kelola. */
    public function coverage(): JsonResponse
    {
        return $this->successResponse($this->directory->coverage(), 'Peta penugasan berhasil diambil');
    }

    /** Supervisor yang memenuhi syarat untuk menilai pegawai tertentu. */
    public function eligible(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supervisee_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'category' => ['nullable', 'string', 'in:'.implode(',', InstrumentCategory::values())],
        ]);

        $supervisee = Employee::query()->findOrFail($data['supervisee_employee_id']);
        $category = isset($data['category']) ? InstrumentCategory::from($data['category']) : null;

        return $this->successResponse(
            $this->directory->eligibleFor($supervisee, $category)->map(fn (SupSupervisor $supervisor) => $this->payload($supervisor) + [
                'eligible' => (bool) $supervisor->getAttribute('eligible'),
                'reason' => $supervisor->getAttribute('reason'),
            ]),
            'Daftar supervisor yang memenuhi syarat',
        );
    }

    /** Tambahkan pegawai ke registry supervisor. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $employee = Employee::query()->findOrFail($data['employee_id']);

        $supervisor = $this->directory->register($employee, $data, $this->actor($request));

        return $this->createdResponse($this->payload($supervisor, true), 'Supervisor berhasil ditambahkan');
    }

    public function show(SupSupervisor $supervisor): JsonResponse
    {
        $assignments = SupAssignment::query()
            ->with('supervisee:id,full_name,position')
            ->where('supervisor_employee_id', $supervisor->employee_id)
            ->active()
            ->get();

        return $this->successResponse([
            ...$this->payload($supervisor->load('employee'), true),
            'assignments' => $assignments->map(fn (SupAssignment $assignment) => [
                'assignment_id' => $assignment->id,
                'supervisee' => $assignment->supervisee?->full_name,
                'role' => $assignment->role->value,
                'approach' => $assignment->approach->value,
                'min_visits' => $assignment->min_visits,
            ]),
        ], 'Detail supervisor berhasil diambil');
    }

    public function update(Request $request, SupSupervisor $supervisor): JsonResponse
    {
        $data = $this->validatePayload($request, partial: true);

        return $this->successResponse(
            $this->payload($this->directory->update($supervisor, $data, $this->actor($request)), true),
            'Pengaturan supervisor diperbarui',
        );
    }

    /** Nonaktifkan (riwayat penugasan & penilaian tetap tersimpan). */
    public function destroy(Request $request, SupSupervisor $supervisor): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->directory->deactivate($supervisor, $this->actor($request), $data['reason'] ?? null);

        return $this->successResponse(null, 'Supervisor dinonaktifkan');
    }

    /** Jenis supervisor beserta kewenangan bawaannya (untuk pilihan di UI). */
    public function kinds(): JsonResponse
    {
        return $this->successResponse(
            collect(SupervisorKind::cases())->map(fn (SupervisorKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'may_finalize' => $kind->mayFinalize(),
                'may_appraise' => $kind->mayAppraise(),
            ]),
            'Jenis supervisor',
        );
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    protected function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'employee_id' => [$required, 'integer', 'exists:employees,id'],
            'kind' => [$required, 'string', 'in:'.implode(',', SupervisorKind::values())],
            'scope_type' => ['nullable', 'string', 'max:30'],
            'scope_id' => ['nullable', 'integer'],
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => ['integer'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', InstrumentCategory::values())],
            'can_finalize' => ['nullable', 'boolean'],
            'can_appraise' => ['nullable', 'boolean'],
            'max_active_cases' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'certification_no' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @param array<string,mixed> $data */
    protected function validatePayload(Request $request, bool $partial = false): array
    {
        return $request->validate($this->rules($partial));
    }

    /** @return array<string,mixed> */
    protected function payload(SupSupervisor $supervisor, bool $detailed = false): array
    {
        $base = [
            'id' => $supervisor->id,
            'employee_id' => $supervisor->employee_id,
            'full_name' => $supervisor->employee?->full_name,
            'nip' => $supervisor->employee?->nip,
            'position' => $supervisor->employee?->position,
            'employee_type' => $supervisor->employee?->employee_type,
            'kind' => $supervisor->kind->value,
            'kind_label' => $supervisor->kind->label(),
            'is_active' => $supervisor->is_active,
            'can_finalize' => $supervisor->mayFinalize(),
            'can_appraise' => $supervisor->mayAppraise(),
            'max_active_cases' => $supervisor->max_active_cases,
            'active_from' => $supervisor->active_from?->toDateString(),
            'active_to' => $supervisor->active_to?->toDateString(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'scope_type' => $supervisor->scope_type,
            'scope_id' => $supervisor->scope_id,
            'subject_ids' => $supervisor->subject_ids,
            'categories' => $supervisor->categories,
            'certification_no' => $supervisor->certification_no,
            'notes' => $supervisor->notes,
            'created_at' => $supervisor->created_at?->toIso8601String(),
        ];
    }
}
