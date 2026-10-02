<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\EmployeeNumberGenerator;
use App\Modules\Hrd\Application\Support\LegacyEmployeeBridge;
use App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdConflictException;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Domain\StateMachines\EmployeeLifecycleStateMachine;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrEmployeeMerge;
use App\Modules\Hrd\Models\HrEmployeeStatusHistory;
use App\Modules\Hrd\Models\HrPerson;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pegawai — pembuatan, perubahan, status, arsip, dan penggabungan (§12, §17, §18, §58, §59).
 *
 * Yang perlu dipahami sebelum membaca: **HRD tidak pernah menimpa data pegawai
 * secara langsung untuk perubahan organisasi.** Transfer, promosi, dan pemutusan
 * hubungan kerja melewati mesin transaksi (LifecycleTransactionService). Layanan
 * ini menangani hal lain: identitas pegawai, perubahan data personal, status
 * administratif, arsip, dan penggabungan duplikat — dengan satu syarat yang sama:
 * setiap perubahan meninggalkan jejak audit dan linimasa.
 */
class EmployeeService
{
    public function __construct(
        private readonly EmployeeNumberGenerator $numbers,
        private readonly LegacyEmployeeBridge $bridge,
        private readonly ReferenceService $references,
        private readonly AssignmentService $assignments,
        private readonly PositionService $positions,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly HrdNotificationService $notifications,
        private readonly UserAccountProviderInterface $users,
        private readonly EmployeeLifecycleStateMachine $machine,
    ) {}

    /* ── Buat & perbarui ────────────────────────────────────────────────── */

    /**
     * Buat pegawai baru beserta orangnya (dan opsional penugasan pertamanya).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): HrEmployee
    {
        $this->references->assertValid('worker_type', $attributes['worker_type'] ?? null, 'worker_type');
        $this->references->assertValid('employment_type', $attributes['employment_type'] ?? null, 'employment_type');
        $this->references->assertValid('employee_status', $attributes['employment_status'] ?? null, 'employment_status');

        return DB::transaction(function () use ($attributes, $actor): HrEmployee {
            $person = $this->resolvePerson($attributes, $actor);
            $personData = $attributes['person'] ?? [];
            $person->fill(array_intersect_key($personData, array_flip([
                'nik', 'full_name', 'preferred_name', 'gender', 'birth_place', 'birth_date', 'marital_status',
                'religion', 'nationality', 'phone', 'mobile', 'personal_email', 'work_email', 'address',
                'city', 'province', 'postal_code', 'country',
            ])));
            $person->updated_by = $actor->getKey();
            $person->save();

            $lifecycleState = $attributes['lifecycle_state'] ?? HrEmployee::LIFECYCLE_DRAFT;
            $organizationId = $attributes['organization_id'] ?? null;

            $employee = HrEmployee::query()->create([
                'employee_number' => $attributes['employee_number']
                    ?? $this->numbers->next('employee', ['organization_id' => $organizationId]),
                'person_id' => $person->getKey(),
                'organization_id' => $organizationId,
                'department_id' => $attributes['department_id'] ?? null,
                'position_id' => $attributes['position_id'] ?? null,
                'location_id' => $attributes['location_id'] ?? null,
                'manager_employee_id' => $attributes['manager_employee_id'] ?? null,
                'lifecycle_state' => $lifecycleState,
                'employment_status' => $attributes['employment_status'] ?? 'DRAFT',
                'worker_type' => $attributes['worker_type'] ?? null,
                'employment_type' => $attributes['employment_type'] ?? null,
                'hire_date' => $attributes['hire_date'] ?? null,
                'probation_end_date' => $attributes['probation_end_date'] ?? null,
                'is_active' => (bool) ($attributes['is_active'] ?? ! in_array($lifecycleState, [HrEmployee::LIFECYCLE_INACTIVE, HrEmployee::LIFECYCLE_ARCHIVED], true)),
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            // Identitas tambahan (NIP, NPWP, BPJS) — opsional pada saat pembuatan.
            foreach ((array) ($attributes['identifiers'] ?? []) as $identifier) {
                $employee->identifiers()->create([
                    'type_code' => $identifier['type_code'],
                    'number' => $identifier['number'],
                    'label' => $identifier['label'] ?? null,
                    'issued_date' => $identifier['issued_date'] ?? null,
                    'expiry_date' => $identifier['expiry_date'] ?? null,
                    'is_primary' => $identifier['is_primary'] ?? false,
                ]);
            }

            if (! empty($attributes['assignment'])) {
                $this->assignments->assign($employee, [
                    ...$attributes['assignment'],
                    'is_primary' => true,
                    'assignment_type' => 'primary',
                ], $actor);
            }

            $this->bridge->project($employee);

            $this->timeline->record($employee, 'employee.created', 'Bergabung dengan yayasan', [
                'category' => 'lifecycle',
                'occurred_at' => now(),
                'effective_date' => $employee->hire_date?->toDateString(),
                'source_type' => HrEmployee::class,
                'source_id' => $employee->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['employee_number' => $employee->employee_number],
            ]);

            $this->audit->log($actor, 'employee.created', $employee, null, $employee->toArray(), null, null, (int) $employee->getKey());

            $this->notifications->notify(
                'employee.created',
                'Pegawai baru terdaftar',
                $employee->displayName().' ('.$employee->employee_number.') telah ditambahkan.',
                [],
                (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
                $employee,
                ['employee_id' => (int) $employee->getKey()],
            );

            return $employee->refresh();
        });
    }

    /**
     * Perbarui data pegawai (identitas orang + penempatan administratif).
     *
     * `$expectedVersion` menegakkan kunci optimistis: dua HR yang membuka layar
     * yang sama tidak saling menimpa tanpa tahu (§78).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(HrEmployee $employee, array $attributes, User $actor, ?int $expectedVersion = null): HrEmployee
    {
        return DB::transaction(function () use ($employee, $attributes, $actor, $expectedVersion): HrEmployee {
            $employee = HrEmployee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            if ($expectedVersion !== null && (int) $employee->version !== $expectedVersion) {
                throw HrdConflictException::staleVersion('pegawai', $expectedVersion, (int) $employee->version);
            }

            $before = $employee->toArray();
            $personBefore = $employee->person?->toArray();

            $personData = $attributes['person'] ?? [];
            if ($personData !== [] && $employee->person !== null) {
                $this->assertUniquePersonIdentity($personData, $employee->person);
                $employee->person->fill(array_intersect_key($personData, array_flip([
                    'nik', 'full_name', 'preferred_name', 'gender', 'birth_place', 'birth_date', 'marital_status',
                    'religion', 'nationality', 'phone', 'mobile', 'personal_email', 'work_email', 'address',
                    'city', 'province', 'postal_code', 'country', 'photo_path',
                ])));
                $employee->person->updated_by = $actor->getKey();
                $employee->person->version = (int) $employee->person->version + 1;
                $employee->person->save();
            }

            $employeeFields = array_intersect_key($attributes, array_flip([
                'hire_date', 'probation_end_date', 'confirmation_date', 'worker_type', 'employment_type',
                'location_id', 'manager_employee_id', 'notes', 'employment_status',
            ]));

            $this->references->assertValid('worker_type', $employeeFields['worker_type'] ?? null, 'worker_type');
            $this->references->assertValid('employment_type', $employeeFields['employment_type'] ?? null, 'employment_type');
            $this->references->assertValid('employee_status', $employeeFields['employment_status'] ?? null, 'employment_status');

            $employee->fill($employeeFields);
            $employee->updated_by = $actor->getKey();
            $employee->version = (int) $employee->version + 1;
            $employee->save();

            $this->bridge->project($employee->refresh());

            $this->audit->log($actor, 'employee.updated', $employee, $before, $employee->toArray(), $attributes['reason'] ?? null, null, (int) $employee->getKey());

            if ($personBefore !== null && $employee->person !== null) {
                $this->audit->log($actor, 'person.updated', $employee->person, $personBefore, $employee->person->toArray(), $attributes['reason'] ?? null, null, (int) $employee->getKey());
            }

            return $employee->refresh();
        });
    }

    /* ── Status & siklus hidup (§18, §68) ───────────────────────────────── */

    /**
     * Ubah keadaan siklus hidup pegawai — selalu lewat mesin keadaan, selalu berjejak.
     *
     * Dipanggil oleh mesin transaksi (setelah persetujuan) maupun oleh HR berizin
     * untuk perubahan administratif. Tidak ada jalur lain yang boleh mengubah
     * `lifecycle_state`.
     */
    public function changeLifecycleState(
        HrEmployee $employee,
        string $newState,
        User $actor,
        ?string $statusCode = null,
        ?string $effectiveDate = null,
        ?string $reason = null,
        ?HrTransaction $transaction = null,
        bool $skipTransitionCheck = false,
    ): HrEmployee {
        return DB::transaction(function () use ($employee, $newState, $actor, $statusCode, $effectiveDate, $reason, $transaction, $skipTransitionCheck): HrEmployee {
            $employee = HrEmployee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $before = $employee->toArray();
            $from = (string) $employee->lifecycle_state;

            if (! $skipTransitionCheck) {
                $this->machine->assert($from, $newState);
            }

            $this->references->assertValid('employee_status', $statusCode, 'employment_status');

            $attributes = [
                'lifecycle_state' => $newState,
                'updated_by' => $actor->getKey(),
                'version' => (int) $employee->version + 1,
            ];

            if ($statusCode !== null) {
                $attributes['employment_status'] = $statusCode;
            }

            $attributes['is_active'] = ! in_array($newState, [
                HrEmployee::LIFECYCLE_INACTIVE, HrEmployee::LIFECYCLE_ARCHIVED, HrEmployee::LIFECYCLE_DRAFT,
            ], true);

            $employee->forceFill($attributes)->save();

            HrEmployeeStatusHistory::query()->create([
                'employee_id' => $employee->getKey(),
                'from_status' => $before['employment_status'] ?? null,
                'to_status' => $employee->employment_status,
                'lifecycle_from' => $from,
                'lifecycle_to' => $newState,
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'reason' => $reason,
                'transaction_id' => $transaction?->getKey(),
                'actor_id' => $actor->getKey(),
            ]);

            $this->bridge->project($employee);

            $this->timeline->record($employee, 'employee.status_changed', 'Status: '.$this->machine->label($from).' → '.$this->machine->label($newState), [
                'category' => 'lifecycle',
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'source_type' => HrEmployeeStatusHistory::class,
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['from' => $from, 'to' => $newState, 'reason' => $reason],
            ]);

            $this->audit->log($actor, 'employee.status_changed', $employee, $before, $employee->toArray(), $reason, $transaction?->getKey(), (int) $employee->getKey());

            $this->notifications->notify(
                'employee.status_changed',
                'Status kepegawaian berubah',
                $employee->displayName().' kini berstatus '.$this->machine->label($newState).'.',
                $employee->user_id === null ? [] : [(int) $employee->user_id],
                [],
                $employee,
            );

            return $employee->refresh();
        });
    }

    /** Aktifkan pegawai (pra-kerja/percobaan → bekerja). */
    public function activate(HrEmployee $employee, User $actor, ?string $effectiveDate = null, ?string $reason = null): HrEmployee
    {
        return $this->changeLifecycleState($employee, HrEmployee::LIFECYCLE_ACTIVE, $actor, 'ACTIVE', $effectiveDate, $reason);
    }

    /** Nonaktifkan pegawai (mis. kontrak berakhir) — data tetap utuh (§59). */
    public function deactivate(HrEmployee $employee, User $actor, ?string $effectiveDate = null, ?string $reason = null): HrEmployee
    {
        return $this->changeLifecycleState($employee, HrEmployee::LIFECYCLE_INACTIVE, $actor, 'INACTIVE', $effectiveDate, $reason);
    }

    /** Arsipkan: hanya untuk pegawai yang sudah tidak aktif (§59). */
    public function archive(HrEmployee $employee, User $actor, ?string $reason = null): HrEmployee
    {
        if (! in_array($employee->lifecycle_state, [HrEmployee::LIFECYCLE_INACTIVE, HrEmployee::LIFECYCLE_DRAFT], true)) {
            throw HrdValidationException::forField(
                'lifecycle_state',
                'Hanya pegawai berstatus tidak aktif yang dapat diarsipkan. Nonaktifkan lebih dulu.',
                'hr.employee.archive_requires_inactive',
            );
        }

        return DB::transaction(function () use ($employee, $actor, $reason): HrEmployee {
            $employee = $this->changeLifecycleState(
                $employee,
                HrEmployee::LIFECYCLE_ARCHIVED,
                $actor,
                'ARCHIVED',
                now()->toDateString(),
                $reason,
            );

            $employee->forceFill(['archived_at' => now(), 'archive_reason' => $reason])->save();
            $this->bridge->detach($employee);

            return $employee->refresh();
        });
    }

    /** Kembalikan dari arsip (masih membutuhkan proses rehire untuk kembali bekerja). */
    public function restore(HrEmployee $employee, User $actor, ?string $reason = null): HrEmployee
    {
        if ($employee->lifecycle_state !== HrEmployee::LIFECYCLE_ARCHIVED) {
            throw HrdValidationException::forField('lifecycle_state', 'Pegawai ini tidak berada dalam arsip.', 'hr.employee.not_archived');
        }

        $employee = $this->changeLifecycleState($employee, HrEmployee::LIFECYCLE_INACTIVE, $actor, 'INACTIVE', now()->toDateString(), $reason);
        $employee->forceFill(['archived_at' => null, 'archive_reason' => null])->save();

        return $employee->refresh();
    }

    /* ── Akun pengguna (§67) ────────────────────────────────────────────── */

    public function linkUser(HrEmployee $employee, int $userId, User $actor): HrEmployee
    {
        $before = $employee->toArray();

        $employee->forceFill(['user_id' => $userId])->save();
        $this->users->link((int) $employee->getKey(), $userId);

        $this->audit->log($actor, 'employee.user_linked', $employee, $before, $employee->toArray(), null, null, (int) $employee->getKey());

        return $employee->refresh();
    }

    public function unlinkUser(HrEmployee $employee, User $actor, ?string $reason = null): HrEmployee
    {
        $before = $employee->toArray();

        $employee->forceFill(['user_id' => null])->save();
        $this->users->unlink((int) $employee->getKey());

        $this->audit->log($actor, 'employee.user_unlinked', $employee, $before, $employee->toArray(), $reason, null, (int) $employee->getKey());

        return $employee->refresh();
    }

    /* ── Pencarian & daftar (§61, §72) ──────────────────────────────────── */

    /**
     * Kueri daftar pegawai dengan penyaring yang benar-benar dipakai layar.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<HrEmployee>
     */
    public function query(array $filters = []): Builder
    {
        $query = HrEmployee::query()
            ->with([
                'person:id,full_name,preferred_name,nik,photo_path,mobile,work_email,personal_email,birth_date,gender',
                'organization:id,name,code',
                'department:id,name,code',
                'position:id,title,code',
                'location:id,name',
                'manager:id,employee_number,person_id',
                'manager.person:id,full_name',
            ]);

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';

            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('employee_number', 'like', $term)
                    ->orWhereHas('person', fn (Builder $person) => $person
                        ->where('full_name', 'like', $term)
                        ->orWhere('preferred_name', 'like', $term)
                        ->orWhere('nik', 'like', $term)
                        ->orWhere('mobile', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('personal_email', 'like', $term)
                        ->orWhere('work_email', 'like', $term))
                    ->orWhereHas('identifiers', fn (Builder $identifier) => $identifier->where('number', 'like', $term))
                    ->orWhereHas('contracts', fn (Builder $contract) => $contract->where('contract_number', 'like', $term))
                    ->orWhereHas('assignments.position', fn (Builder $position) => $position->where('title', 'like', $term));
            });
        }

        foreach (['organization_id', 'department_id', 'position_id', 'location_id', 'worker_type', 'employment_type', 'lifecycle_state'] as $field) {
            if (($filters[$field] ?? null) !== null && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['employment_status'])) {
            $query->whereIn('employment_status', (array) $filters['employment_status']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (($filters['lifecycle'] ?? null) === 'working') {
            $query->whereIn('lifecycle_state', [HrEmployee::LIFECYCLE_ACTIVE, HrEmployee::LIFECYCLE_PROBATION, HrEmployee::LIFECYCLE_CONFIRMED]);
        }

        if (($filters['lifecycle'] ?? null) === 'archived') {
            $query->archived();
        }

        if (! empty($filters['contract_status'])) {
            $query->whereHas('contracts', fn (Builder $contract) => $contract->where('status', $filters['contract_status']));
        }

        if (! empty($filters['contract_expiring_days'])) {
            $query->whereHas('contracts', fn (Builder $contract) => $contract->expiringWithin((int) $filters['contract_expiring_days']));
        }

        if (! empty($filters['expiring_certification_days'])) {
            $query->whereHas('certifications', fn (Builder $certification) => $certification->expiringWithin((int) $filters['expiring_certification_days']));
        }

        if (! empty($filters['hire_date_from'])) {
            $query->where('hire_date', '>=', $filters['hire_date_from']);
        }

        if (! empty($filters['hire_date_to'])) {
            $query->where('hire_date', '<=', $filters['hire_date_to']);
        }

        if (! empty($filters['manager_employee_id'])) {
            $query->where('manager_employee_id', $filters['manager_employee_id']);
        }

        if (! empty($filters['ids'])) {
            $query->whereIn('id', (array) $filters['ids']);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderBy($filters['sort_by'] ?? 'employee_number', $filters['sort_dir'] ?? 'asc')
            ->paginate(min(200, max(1, $perPage)));
    }

    /* ── Penggabungan duplikat (§58) ────────────────────────────────────── */

    /**
     * Bandingkan dua pegawai kolom per kolom untuk ditinjau manusia.
     *
     * @return array<string, mixed>
     */
    public function compareForMerge(HrEmployee $source, HrEmployee $target): array
    {
        $fields = [
            'employee_number', 'hire_date', 'organization_id', 'department_id', 'position_id',
            'location_id', 'lifecycle_state', 'employment_status', 'worker_type', 'employment_type',
            'user_id', 'legacy_employee_id',
        ];

        $differences = [];

        foreach ($fields as $field) {
            if ((string) $source->{$field} !== (string) $target->{$field}) {
                $differences[$field] = [
                    'source' => $source->{$field},
                    'target' => $target->{$field},
                    'suggested' => $field === 'employee_number' ? $target->{$field} : ($target->{$field} ?? $source->{$field}),
                ];
            }
        }

        $personFields = ['full_name', 'nik', 'gender', 'birth_date', 'mobile', 'personal_email', 'address'];

        foreach ($personFields as $field) {
            $left = $source->person?->{$field};
            $right = $target->person?->{$field};

            if ((string) $left !== (string) $right) {
                $differences["person.{$field}"] = [
                    'source' => $left,
                    'target' => $right,
                    'suggested' => $right ?? $left,
                ];
            }
        }

        return [
            'differences' => $differences,
            'counts' => [
                'assignments' => $source->assignments()->count(),
                'documents' => $source->documents()->count(),
                'certifications' => $source->certifications()->count(),
                'skills' => $source->skills()->count(),
                'dependents' => $source->dependents()->count(),
                'educations' => $source->educations()->count(),
                'timeline_events' => $source->timelineEvents()->count(),
                'transactions' => $source->transactions()->count(),
            ],
        ];
    }

    /**
     * Ajukan penggabungan (menunggu persetujuan). Belum mengubah apa pun.
     *
     * @param  array<string, mixed>  $resolution
     */
    public function proposeMerge(HrEmployee $source, HrEmployee $target, array $resolution, User $actor, ?string $reason = null): HrEmployeeMerge
    {
        if ((int) $source->getKey() === (int) $target->getKey()) {
            throw HrdValidationException::forField('target_employee_id', 'Pegawai sumber dan tujuan tidak boleh sama.', 'hr.merge.same_employee');
        }

        $comparison = $this->compareForMerge($source, $target);

        $merge = HrEmployeeMerge::query()->create([
            'source_employee_id' => $source->getKey(),
            'target_employee_id' => $target->getKey(),
            'status' => HrEmployeeMerge::STATUS_SUBMITTED,
            'comparison' => $comparison,
            'resolution' => $resolution,
            'requested_by' => $actor->getKey(),
            'reason' => $reason,
        ]);

        $this->audit->log($actor, 'employee.merge_proposed', $merge, null, $merge->toArray(), $reason, null, (int) $source->getKey());

        return $merge;
    }

    /**
     * Jalankan penggabungan: pindahkan seluruh catatan historis, tandai sumber.
     *
     * Tidak ada baris yang dihapus (§58, §59): penugasan, dokumen, sertifikat,
     * linimasa, dan dokumen lain dipindahkan; pegawai sumber ditandai `merged`
     * dan tetap dapat ditelusuri.
     */
    public function applyMerge(HrEmployeeMerge $merge, User $actor): HrEmployeeMerge
    {
        return DB::transaction(function () use ($merge, $actor): HrEmployeeMerge {
            $merge = HrEmployeeMerge::query()->whereKey($merge->getKey())->lockForUpdate()->firstOrFail();

            if ($merge->status === HrEmployeeMerge::STATUS_MERGED) {
                throw HrdConflictException::duplicateRequest('merge:'.$merge->getKey());
            }

            $source = HrEmployee::query()->whereKey($merge->source_employee_id)->lockForUpdate()->firstOrFail();
            $target = HrEmployee::query()->whereKey($merge->target_employee_id)->lockForUpdate()->firstOrFail();

            $moved = [];

            $relations = [
                'assignments' => HrEmployeeAssignment::class,
                'documents' => \App\Modules\Hrd\Models\HrEmployeeDocument::class,
                'certifications' => \App\Modules\Hrd\Models\HrEmployeeCertification::class,
                'skills' => \App\Modules\Hrd\Models\HrEmployeeSkill::class,
                'dependents' => \App\Modules\Hrd\Models\HrEmployeeDependent::class,
                'educations' => \App\Modules\Hrd\Models\HrEmployeeEducation::class,
                'contacts' => \App\Modules\Hrd\Models\HrEmployeeContact::class,
                'addresses' => \App\Modules\Hrd\Models\HrEmployeeAddress::class,
                'emergencyContacts' => \App\Modules\Hrd\Models\HrEmergencyContact::class,
                'employmentRecords' => \App\Modules\Hrd\Models\HrEmploymentRecord::class,
                'contracts' => \App\Modules\Hrd\Models\HrContract::class,
                'probations' => \App\Modules\Hrd\Models\HrProbation::class,
                'timelineEvents' => \App\Modules\Hrd\Models\HrEmployeeTimelineEvent::class,
                'statusHistories' => \App\Modules\Hrd\Models\HrEmployeeStatusHistory::class,
                'identifiers' => \App\Modules\Hrd\Models\HrEmployeeIdentifier::class,
                'transactions' => HrTransaction::class,
                'requests' => \App\Modules\Hrd\Models\HrRequest::class,
                'notes' => \App\Modules\Hrd\Models\HrNote::class,
                'managerRelations' => \App\Modules\Hrd\Models\HrEmployeeManager::class,
            ];

            foreach ($relations as $relation => $model) {
                $count = $model::query()->where('employee_id', $source->getKey())->update(['employee_id' => $target->getKey()]);
                $moved[$relation] = $count;
            }

            // Dokumen yang menunjuk pegawai sumber dari sisi lain.
            \App\Modules\Hrd\Models\HrEmployeeManager::query()
                ->where('manager_employee_id', $source->getKey())
                ->update(['manager_employee_id' => $target->getKey()]);

            HrEmployee::query()->where('manager_employee_id', $source->getKey())->update(['manager_employee_id' => $target->getKey()]);

            $source->forceFill([
                'lifecycle_state' => HrEmployee::LIFECYCLE_ARCHIVED,
                'is_active' => false,
                'archived_at' => now(),
                'archive_reason' => 'Digabungkan ke '.$target->employee_number,
                'notes' => trim((string) $source->notes.' | Digabungkan ke '.$target->employee_number, ' |'),
            ])->save();

            $this->bridge->detach($source);

            $merge->forceFill([
                'status' => HrEmployeeMerge::STATUS_MERGED,
                'moved_records' => $moved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'merged_at' => now(),
            ])->save();

            $this->timeline->record($target, 'employee.merged', 'Data pegawai duplikat digabungkan ke profil ini', [
                'category' => 'lifecycle',
                'visibility' => 'hr',
                'actor' => $actor,
                'meta' => ['source_employee_id' => (int) $source->getKey(), 'moved' => $moved],
            ]);

            $this->audit->log($actor, 'employee.merged', $merge, null, $merge->toArray(), $merge->reason, null, (int) $target->getKey());

            return $merge->refresh();
        });
    }

    /* ── Ringkasan profil (§23, §85) ────────────────────────────────────── */

    /**
     * Kartu pegawai untuk layar self-service & direktori. Sengaja hanya memuat
     * hal yang dimengerti pengguna: nama, jabatan, unit, atasan — bukan kunci asing.
     *
     * @return array<string, mixed>
     */
    public function summaryCard(HrEmployee $employee, ?string $onDate = null): array
    {
        $assignment = $this->assignments->currentPrimary($employee, $onDate);

        return [
            'id' => (int) $employee->getKey(),
            'employee_number' => $employee->employee_number,
            'name' => $employee->displayName(),
            'preferred_name' => $employee->person?->preferred_name,
            'photo_url' => $employee->person?->photo_path,
            'position' => $assignment?->position?->title ?? $employee->position?->title,
            'position_code' => $assignment?->position?->code ?? $employee->position?->code,
            'department' => $assignment?->department?->name ?? $employee->department?->name,
            'organization' => $assignment?->organization?->name ?? $employee->organization?->name,
            'location' => $assignment?->location?->name ?? $employee->location?->name,
            'manager' => $employee->manager?->displayName(),
            'manager_id' => $employee->manager_employee_id,
            'lifecycle_state' => $employee->lifecycle_state,
            'lifecycle_label' => $this->machine->label((string) $employee->lifecycle_state),
            'employment_status' => $employee->employment_status,
            'employment_status_label' => $this->references->label('employee_status', $employee->employment_status),
            'worker_type' => $employee->worker_type,
            'worker_type_label' => $this->references->label('worker_type', $employee->worker_type),
            'employment_type' => $employee->employment_type,
            'employment_type_label' => $this->references->label('employment_type', $employee->employment_type),
            'hire_date' => $employee->hire_date?->toDateString(),
            'service_years' => $employee->hire_date === null ? null : (int) $employee->hire_date->diffInYears(now()),
            'is_active' => $employee->isActive(),
            'user_linked' => $employee->user_id !== null,
            'active_assignment_count' => $employee->assignments()->where('status', 'active')->count(),
            'has_open_transaction' => $employee->transactions()->whereIn('status', ['draft', 'submitted', 'review', 'approved'])->exists(),
        ];
    }

    /* ── Bantu ──────────────────────────────────────────────────────────── */

    /**
     * Gunakan orang yang sudah ada bila NIK/nomor identitas cocok (§56: jangan
     * menggandakan orang hanya karena formulir tidak menemukannya).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolvePerson(array $attributes, User $actor): HrPerson
    {
        $personData = $attributes['person'] ?? [];
        $nik = $personData['nik'] ?? null;

        if ($nik !== null) {
            $existing = HrPerson::query()->where('nik', $nik)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if (empty($personData['full_name'])) {
            throw HrdValidationException::forField('person.full_name', 'Nama lengkap pegawai wajib diisi.', 'hr.employee.name_required');
        }

        return HrPerson::query()->create([
            ...$personData,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }

    /** @param array<string, mixed> $personData */
    private function assertUniquePersonIdentity(array $personData, HrPerson $person): void
    {
        if (! empty($personData['nik'])) {
            $taken = HrPerson::query()
                ->where('nik', $personData['nik'])
                ->whereKeyNot($person->getKey())
                ->exists();

            if ($taken) {
                throw HrdValidationException::forField('person.nik', 'NIK ini sudah terdaftar pada orang lain.', 'hr.person.nik_taken');
            }
        }
    }
}
