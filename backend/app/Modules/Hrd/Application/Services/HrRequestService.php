<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\EmployeeNumberGenerator;
use App\Modules\Hrd\Domain\Enums\HrRequestStatus;
use App\Modules\Hrd\Domain\Enums\Priority;
use App\Modules\Hrd\Domain\Exceptions\HrdConflictException;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrPerson;
use App\Modules\Hrd\Models\HrRequest;
use App\Modules\Hrd\Models\HrRequestItem;
use App\Modules\Hrd\Models\HrRequestType;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan HR & permintaan perubahan data (§45, §46).
 *
 * Nilai disimpan dalam tiga tahap — diminta → disetujui → diterapkan — karena
 * ketiganya bisa berbeda, dan perbedaan itu sendiri adalah informasi: HR dapat
 * menyetujui sebagian, atau menyetujui lalu memutuskan menerapkan sebagian.
 *
 * `editable_fields` pada jenis permintaan adalah daftar putih kolom: pegawai tidak
 * dapat meminta perubahan nomor pegawai atau status kepegawaian hanya karena
 * formulirnya menerima apa saja.
 */
class HrRequestService
{
    public function __construct(
        private readonly EmployeeNumberGenerator $numbers,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly HrdNotificationService $notifications,
        private readonly HrdWorkflowService $workflow,
        private readonly EmployeeService $employees,
    ) {}

    /** @param array<string, mixed> $payload */
    public function create(HrEmployee $employee, string $typeCode, array $payload, User $actor, bool $submit = true): HrRequest
    {
        $type = HrRequestType::query()->where('code', $typeCode)->where('is_active', true)->first();

        if ($type === null) {
            throw HrdValidationException::forField('type_code', "Jenis permintaan \"{$typeCode}\" tidak dikenal atau tidak aktif.", 'hr.request.type_unknown');
        }

        return DB::transaction(function () use ($employee, $type, $payload, $actor, $submit): HrRequest {
            $requested = (array) ($payload['requested_values'] ?? []);
            $this->assertFieldsAllowed($type, $requested);

            if ($type->requires_attachment && empty($payload['evidence_document_id'])) {
                throw HrdValidationException::forField('evidence_document_id', "Permintaan \"{$type->name}\" mewajibkan lampiran bukti.", 'hr.request.evidence_required');
            }

            $currentValues = $this->currentValues($employee, array_keys($requested));

            $request = HrRequest::query()->create([
                'request_number' => $this->numbers->next('request', ['organization_id' => $employee->organization_id]),
                'type_code' => $type->code,
                'employee_id' => $employee->getKey(),
                'subject' => $payload['subject'] ?? $type->name,
                'status' => HrRequestStatus::DRAFT->value,
                'current_values' => $currentValues,
                'requested_values' => $requested,
                'reason' => $payload['reason'] ?? null,
                'evidence_document_id' => $payload['evidence_document_id'] ?? null,
                'priority' => $payload['priority'] ?? Priority::NORMAL->value,
                'sla_due_at' => $type->sla_hours === null ? null : now()->addHours((int) $type->sla_hours),
                'idempotency_key' => $payload['idempotency_key'] ?? null,
            ]);

            foreach ($requested as $field => $value) {
                HrRequestItem::query()->create([
                    'request_id' => $request->getKey(),
                    'field' => $field,
                    'label' => $this->labelFor($field),
                    'current_value' => ['value' => $currentValues[$field] ?? null],
                    'requested_value' => ['value' => $value],
                    'status' => 'pending',
                ]);
            }

            $this->audit->log($actor, 'request.created', $request, null, $request->toArray(), $request->reason, null, (int) $employee->getKey());

            if ($submit) {
                return $this->submit($request, $actor);
            }

            return $request->refresh();
        });
    }

    public function submit(HrRequest $request, User $actor): HrRequest
    {
        return DB::transaction(function () use ($request, $actor): HrRequest {
            $request = HrRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (HrRequestStatus::from((string) $request->status) !== HrRequestStatus::DRAFT) {
                return $request;
            }

            $request->forceFill([
                'status' => HrRequestStatus::SUBMITTED->value,
                'submitted_at' => now(),
                'version' => (int) $request->version + 1,
            ])->save();

            $type = $request->type;
            $definitionCode = $type?->workflow_definition_code ?? (string) config('hrd.workflows.request');

            $started = $this->workflow->startApproval(
                $definitionCode,
                'hrd_request',
                (int) $request->getKey(),
                [
                    'request_number' => $request->request_number,
                    'type_code' => $request->type_code,
                    'employee_id' => (int) $request->employee_id,
                ],
                $actor,
            );

            if ($started['instance_id'] !== null || $started['started']) {
                $request->forceFill([
                    'workflow_instance_id' => $started['instance_id'],
                    'workflow_status' => $started['status'],
                ])->save();
            }

            $this->timeline->record($request->employee_id, 'request.submitted', 'Permintaan diajukan: '.($request->subject ?? $request->type_code), [
                'category' => 'request',
                'source_type' => HrRequest::class,
                'source_id' => $request->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['request_number' => $request->request_number],
            ]);

            $this->audit->log($actor, 'request.submitted', $request, null, $request->toArray(), null, null, (int) $request->employee_id);

            $this->notifyApprovers($request, 'request.submitted', 'Permintaan HR menunggu persetujuan');

            return $request->refresh();
        });
    }

    /** @param array<string, mixed> $approvedValues */
    public function approve(HrRequest $request, User $actor, ?array $approvedValues = null, ?string $notes = null): HrRequest
    {
        return DB::transaction(function () use ($request, $actor, $approvedValues, $notes): HrRequest {
            $request = HrRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, [HrRequestStatus::SUBMITTED->value, HrRequestStatus::REVIEW->value], true)) {
                throw HrdValidationException::forField('status', 'Permintaan ini tidak dalam keadaan menunggu persetujuan.', 'hr.request.not_pending');
            }

            $approved = $approvedValues ?? ($request->requested_values ?? []);

            $request->forceFill([
                'status' => HrRequestStatus::APPROVED->value,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_notes' => $notes,
                'version' => (int) $request->version + 1,
            ])->save();

            foreach ($request->items as $item) {
                $item->forceFill([
                    'status' => array_key_exists($item->field, $approved) ? 'approved' : 'rejected',
                    'approved_value' => array_key_exists($item->field, $approved) ? ['value' => $approved[$item->field]] : null,
                ])->save();
            }

            $this->audit->log($actor, 'request.approved', $request, null, $request->toArray(), $notes, null, (int) $request->employee_id);

            $this->timeline->record($request->employee_id, 'request.approved', 'Permintaan disetujui: '.($request->subject ?? ''), [
                'category' => 'request',
                'source_type' => HrRequest::class,
                'source_id' => $request->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
            ]);

            $this->notifications->notify(
                'request.approved',
                'Permintaan HR disetujui',
                ($request->subject ?? $request->request_number).' telah disetujui.',
                array_values(array_filter([(int) ($request->employee?->user_id ?? 0)])),
                [],
                $request->employee,
                ['request_id' => (int) $request->getKey()],
            );

            // Jenis permintaan yang menerapkan otomatis akan langsung dieksekusi;
            // sisanya menunggu HR menekan "terapkan" setelah verifikasi berkas.
            if (($request->type?->auto_apply_on_approval ?? false) === true) {
                return $this->apply($request, $actor);
            }

            return $request->refresh();
        });
    }

    public function reject(HrRequest $request, User $actor, ?string $notes = null): HrRequest
    {
        $request = HrRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        $request->forceFill([
            'status' => HrRequestStatus::REJECTED->value,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
            'decision_notes' => $notes,
            'version' => (int) $request->version + 1,
        ])->save();

        $this->audit->log($actor, 'request.rejected', $request, null, $request->toArray(), $notes, null, (int) $request->employee_id);

        $this->notifications->notify(
            'request.rejected',
            'Permintaan HR ditolak',
            ($request->subject ?? $request->request_number).($notes ? ' — '.$notes : ''),
            array_values(array_filter([(int) ($request->employee?->user_id ?? 0)])),
            [],
            $request->employee,
            ['request_id' => (int) $request->getKey()],
        );

        return $request->refresh();
    }

    /**
     * Terapkan nilai yang disetujui ke data pegawai (§46).
     *
     * Penerapan memakai `EmployeeService` — bukan menulis kolom langsung — supaya
     * jejak audit, linimasa, dan proyeksi ke tabel lama tetap konsisten dengan
     * perubahan lain.
     */
    public function apply(HrRequest $request, User $actor): HrRequest
    {
        return DB::transaction(function () use ($request, $actor): HrRequest {
            $request = HrRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== HrRequestStatus::APPROVED->value && $request->status !== HrRequestStatus::COMPLETED->value) {
                throw HrdValidationException::forField('status', 'Hanya permintaan yang sudah disetujui dapat diterapkan.', 'hr.request.not_approved');
            }

            if ($request->applied_at !== null) {
                return $request;
            }

            $employee = HrEmployee::query()->whereKey($request->employee_id)->firstOrFail();
            $approved = [];

            foreach ($request->items as $item) {
                if ($item->status === 'approved' && is_array($item->approved_value)) {
                    $approved[$item->field] = $item->approved_value['value'] ?? null;
                }
            }

            $this->applyToEmployee($employee, $approved, $actor, $request);

            $request->forceFill([
                'status' => HrRequestStatus::COMPLETED->value,
                'applied_values' => $approved,
                'applied_at' => now(),
                'applied_by' => $actor->getKey(),
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
            ])->save();

            foreach ($request->items as $item) {
                if (array_key_exists($item->field, $approved)) {
                    $item->forceFill(['status' => 'applied', 'applied_at' => now()])->save();
                }
            }

            $this->timeline->record($employee, 'request.applied', 'Perubahan diterapkan: '.($request->subject ?? $request->request_number), [
                'category' => 'request',
                'source_type' => HrRequest::class,
                'source_id' => $request->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['fields' => array_keys($approved)],
            ]);

            $this->audit->log($actor, 'request.applied', $request, $request->current_values, $approved, null, null, (int) $request->employee_id);

            $this->notifications->notify(
                'request.completed',
                'Permintaan HR selesai',
                ($request->subject ?? $request->request_number).' telah diterapkan pada data Anda.',
                array_values(array_filter([(int) ($employee->user_id ?? 0)])),
                [],
                $employee,
                ['request_id' => (int) $request->getKey()],
            );

            return $request->refresh();
        });
    }

    /** Permintaan yang menunggu tindakan, untuk antrean persetujuan (§43, §44). */
    public function pendingQuery(?int $employeeId = null)
    {
        return HrRequest::query()
            ->with(['type:id,code,name,category', 'employee.person:id,full_name', 'employee:id,employee_number,person_id'])
            ->pending()
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderByRaw('case when priority = ? then 0 when priority = ? then 1 when priority = ? then 2 else 3 end', ['urgent', 'high', 'normal'])
            ->orderBy('sla_due_at');
    }

    /** Permintaan yang melampaui SLA (§70 HR request SLA). */
    public function breachingSla(int $limit = 100)
    {
        return HrRequest::query()->with(['employee.person:id,full_name'])->breachingSla()->orderBy('sla_due_at')->limit($limit)->get();
    }

    /* ── Bantu ──────────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $requested */
    private function assertFieldsAllowed(HrRequestType $type, array $requested): void
    {
        if ($requested === []) {
            throw HrdValidationException::forField('requested_values', 'Tidak ada perubahan yang diminta.', 'hr.request.empty');
        }

        $allowed = $type->editable_fields;

        if (! is_array($allowed) || $allowed === []) {
            // Jenis non-perubahan-data (mis. permintaan surat) hanya memuat narasi.
            return;
        }

        $forbidden = array_diff(array_keys($requested), $allowed);

        if ($forbidden !== []) {
            throw HrdValidationException::forField(
                'requested_values',
                'Kolom berikut tidak dapat diminta melalui jenis permintaan ini: '.implode(', ', $forbidden).'.',
                'hr.request.field_not_allowed',
            );
        }
    }

    /**
     * Nilai saat ini untuk kolom yang diminta — diambil dari pegawai/orangnya,
     * bukan dari formulir, sehingga layar persetujuan selalu menampilkan keadaan
     * yang sebenarnya.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function currentValues(HrEmployee $employee, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = match (true) {
                in_array($field, ['full_name', 'nik', 'gender', 'birth_date', 'phone', 'mobile', 'personal_email', 'address', 'city', 'province', 'postal_code'], true)
                    => $employee->person?->{$field},
                default => $employee->{$field} ?? null,
            };
        }

        return $values;
    }

    /** @param array<string, mixed> $approved */
    private function applyToEmployee(HrEmployee $employee, array $approved, User $actor, HrRequest $request): void
    {
        $personFields = ['full_name', 'nik', 'gender', 'birth_date', 'phone', 'mobile', 'personal_email', 'address', 'city', 'province', 'postal_code'];
        $personData = [];
        $employeeData = [];

        foreach ($approved as $field => $value) {
            if (in_array($field, $personFields, true)) {
                $personData[$field] = $value;
            } else {
                $employeeData[$field] = $value;
            }
        }

        if ($personData !== [] && $employee->person instanceof HrPerson) {
            $employee->person->fill($personData);
            $employee->person->updated_by = $actor->getKey();
            $employee->person->save();
        }

        // Kolom penempatan hanya boleh berubah lewat mesin transaksi; permintaan
        // data pegawai tidak pernah memindahkan orang antar unit.
        unset($employeeData['organization_id'], $employeeData['department_id'], $employeeData['position_id'], $employeeData['employee_number']);

        if ($employeeData !== []) {
            $this->employees->update($employee, ['reason' => 'Permintaan '.$request->request_number] + $employeeData, $actor);
        }
    }

    private function labelFor(string $field): string
    {
        return [
            'full_name' => 'Nama Lengkap', 'nik' => 'NIK', 'gender' => 'Jenis Kelamin', 'birth_date' => 'Tanggal Lahir',
            'phone' => 'Telepon', 'mobile' => 'Nomor Seluler', 'personal_email' => 'Surel Pribadi',
            'address' => 'Alamat', 'city' => 'Kota', 'province' => 'Provinsi', 'postal_code' => 'Kode Pos',
            'work_email' => 'Surel Kerja', 'marital_status' => 'Status Perkawinan', 'religion' => 'Agama',
        ][$field] ?? ucwords(str_replace('_', ' ', $field));
    }

    private function notifyApprovers(HrRequest $request, string $event, string $title): void
    {
        $employee = $request->employee;
        $managerId = $employee?->currentManagerId();
        $managerUserId = $managerId === null ? null : HrEmployee::query()->whereKey($managerId)->value('user_id');

        $this->notifications->notify(
            $event,
            $title,
            ($request->subject ?? $request->request_number).' — '.($employee?->displayName() ?? ''),
            array_values(array_filter(array_map('intval', [$managerUserId]))),
            [],
            $employee,
            ['request_id' => (int) $request->getKey()],
        );
    }
}
