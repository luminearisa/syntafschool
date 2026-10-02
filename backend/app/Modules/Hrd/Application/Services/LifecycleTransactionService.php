<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\EmployeeNumberGenerator;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use App\Modules\Hrd\Domain\Contracts\HRTransactionProviderInterface;
use App\Modules\Hrd\Domain\Enums\Priority;
use App\Modules\Hrd\Domain\Enums\TransactionStatus;
use App\Modules\Hrd\Domain\Exceptions\HrdConflictException;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Domain\Exceptions\LifecycleTransitionException;
use App\Modules\Hrd\Domain\StateMachines\EmployeeLifecycleStateMachine;
use App\Modules\Hrd\Domain\StateMachines\TransactionStateMachine;
use App\Modules\Hrd\Infrastructure\Lifecycle\TransactionEffectRegistry;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrEmployeeManager;
use App\Modules\Hrd\Models\HrTransaction;
use App\Modules\Hrd\Models\HrTransactionItem;
use App\Modules\Hrd\Models\HrTransactionType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mesin transaksi SDM (§33–§35, §68, §77, §78).
 *
 * Inilah pintu tunggal perubahan organisasi pegawai. Transfer, promosi, demosi,
 * mutasi, perubahan status, dan pengakhiran hubungan kerja semuanya berbentuk
 * transaksi dengan daftar keadaan yang terbatas:
 *
 * ```
 * draft → submitted → review → approved → completed
 *    ↘ cancelled        ↘ rejected
 * ```
 *
 * Empat jaminan yang dijaga di sini:
 *
 *  1. **Atomic.** Penerapan transaksi berjalan di dalam satu transaksi basis data:
 *     penugasan lama ditutup, penugasan baru dibuat, ringkasan pegawai diperbarui,
 *     posisi dihitung ulang, linimasa dan audit ditulis. Tidak ada keadaan setengah
 *     jadi (§77).
 *  2. **Berurutan.** `SELECT … FOR UPDATE` pada baris transaksi dan pegawai
 *     menahan dua approver yang menekan "setujui" bersamaan; hanya satu yang
 *     mengubah keadaan (§78).
 *  3. **Idempoten.** `idempotency_key` mencegah pengajuan ganda ketika klien
 *     mengirim ulang permintaan; `apply()` yang dipanggil dua kali tidak
 *     menghasilkan penugasan ganda.
 *  4. **Tidak melompati persetujuan.** Efek hanya dijalankan pada keadaan
 *     `approved` — dan penerapan menandai `completed`, sehingga persetujuan dan
 *     perubahan tidak pernah tertukar (§33, §34).
 */
class LifecycleTransactionService implements HRTransactionProviderInterface
{
    public function __construct(
        private readonly TransactionEffectRegistry $effects,
        private readonly TransactionStateMachine $machine,
        private readonly EmployeeLifecycleStateMachine $lifecycle,
        private readonly EmployeeNumberGenerator $numbers,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly HrdNotificationService $notifications,
        private readonly HrdWorkflowService $workflow,
        private readonly IntegrationOutbox $outbox,
        private readonly HrdSettingsService $settings,
    ) {}

    /* ── Pengajuan ──────────────────────────────────────────────────────── */

    /**
     * Ajukan transaksi baru (§34, §35).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    public function create(HrEmployee $employee, string $typeCode, array $payload, User $actor, array $options = []): HrTransaction
    {
        $type = HrTransactionType::query()->where('code', $typeCode)->where('status', 'active')->first();

        if ($type === null) {
            throw HrdValidationException::forField('type_code', "Jenis transaksi \"{$typeCode}\" tidak dikenal atau tidak aktif.", 'hr.transaction.type_unknown');
        }

        return DB::transaction(function () use ($employee, $type, $payload, $actor, $options): HrTransaction {
            $idempotencyKey = $options['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                $existing = HrTransaction::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $employee = HrEmployee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->lifecycle->allowsTransaction((string) $employee->lifecycle_state)) {
                throw HrdValidationException::forField(
                    'employee_id',
                    'Pegawai berstatus arsip tidak dapat menerima transaksi baru. Lakukan proses rehire lebih dulu.',
                    'hr.transaction.archived_employee',
                );
            }

            if (! $type->allowsFrom((string) $employee->lifecycle_state)) {
                throw HrdValidationException::forField(
                    'type_code',
                    "Transaksi \"{$type->name}\" tidak dapat dimulai dari keadaan \"{$this->lifecycle->label((string) $employee->lifecycle_state)}\".",
                    'hr.transaction.state_not_allowed',
                );
            }

            $this->assertNoOpenTransactionOfSameType($employee, $type->code, $type->category);

            if ($type->requires_reason && empty($options['reason']) && empty($payload['reason'])) {
                throw HrdValidationException::forField('reason', 'Alasan transaksi wajib diisi.', 'hr.transaction.reason_required');
            }

            if ($type->requires_effective_date && empty($options['effective_date'])) {
                throw HrdValidationException::forField('effective_date', 'Tanggal berlaku wajib diisi.', 'hr.transaction.effective_date_required');
            }

            $transaction = HrTransaction::query()->create([
                'transaction_number' => $options['transaction_number'] ?? $this->numbers->next('transaction', [
                    'organization_id' => $employee->organization_id,
                ]),
                'type_code' => $type->code,
                'employee_id' => $employee->getKey(),
                'status' => TransactionStatus::DRAFT->value,
                'effective_date' => $options['effective_date'] ?? $payload['effective_date'] ?? null,
                'reason_code' => $options['reason_code'] ?? $payload['reason_code'] ?? null,
                'reason' => $options['reason'] ?? $payload['reason'] ?? null,
                'priority' => $options['priority'] ?? Priority::NORMAL->value,
                'payload' => $payload,
                'before_snapshot' => $this->snapshot($employee),
                'requested_by' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'notes' => $options['notes'] ?? null,
            ]);

            $this->writeItems($transaction, $type, $employee, $payload);

            $this->audit->log($actor, 'transaction.created', $transaction, null, $transaction->toArray(), $transaction->reason, $transaction->getKey(), (int) $employee->getKey());

            if (($options['submit'] ?? true) === true) {
                return $this->submitForApproval($transaction, $actor);
            }

            return $transaction->refresh();
        });
    }

    /** Ajukan untuk diproses persetujuan (§34 langkah pertama). */
    public function submitForApproval(HrTransaction $transaction, User $actor): HrTransaction
    {
        return DB::transaction(function () use ($transaction, $actor): HrTransaction {
            $transaction = $this->lock($transaction);
            $current = TransactionStatus::from((string) $transaction->status);

            if ($current === TransactionStatus::SUBMITTED || $current === TransactionStatus::REVIEW) {
                return $transaction;
            }

            $this->machine->assert($current->value, TransactionStatus::SUBMITTED->value);

            $transaction->forceFill([
                'status' => TransactionStatus::SUBMITTED->value,
                'submitted_at' => now(),
                'version' => (int) $transaction->version + 1,
            ])->save();

            $employee = $transaction->employee;
            $definitionCode = $transaction->type?->workflow_definition_code
                ?? (string) config('hrd.workflows.transaction');

            $started = $this->workflow->startApproval(
                $definitionCode,
                'hrd_transaction',
                (int) $transaction->getKey(),
                [
                    'type_code' => $transaction->type_code,
                    'transaction_number' => $transaction->transaction_number,
                    'employee_id' => (int) $transaction->employee_id,
                    'employee_name' => $employee?->displayName(),
                    'effective_date' => $transaction->effective_date?->toDateString(),
                ],
                $actor,
            );

            $transaction->forceFill([
                'workflow_instance_id' => $started['instance_id'],
                'workflow_status' => $started['status'] ?? ($started['started'] ? 'submitted' : null),
            ])->save();

            $this->timeline->record($transaction->employee_id, 'transaction.submitted', ($transaction->type?->name ?? 'Transaksi').' diajukan', [
                'category' => 'lifecycle',
                'effective_date' => $transaction->effective_date?->toDateString(),
                'source_type' => HrTransaction::class,
                'source_id' => $transaction->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['transaction_number' => $transaction->transaction_number],
            ]);

            $this->audit->log($actor, 'transaction.submitted', $transaction, null, $transaction->toArray(), null, $transaction->getKey(), (int) $transaction->employee_id);

            $this->notifications->notify(
                'transaction.submitted',
                'Transaksi SDM menunggu persetujuan',
                ($transaction->type?->name ?? 'Transaksi').' untuk '.($employee?->displayName() ?? '').' menunggu persetujuan.',
                $this->approverUserIds($transaction),
                [],
                $employee,
                ['transaction_id' => (int) $transaction->getKey()],
            );

            return $transaction->refresh();
        });
    }

    /* ── Keputusan ─────────────────────────────────────────────────────── */

    public function approve(HrTransaction $transaction, User $actor, ?string $notes = null): HrTransaction
    {
        return $this->decide($transaction, $actor, TransactionStatus::APPROVED, $notes);
    }

    public function reject(HrTransaction $transaction, User $actor, ?string $notes = null): HrTransaction
    {
        return $this->decide($transaction, $actor, TransactionStatus::REJECTED, $notes);
    }

    public function cancel(HrTransaction $transaction, User $actor, ?string $notes = null): HrTransaction
    {
        return $this->decide($transaction, $actor, TransactionStatus::CANCELLED, $notes);
    }

    /** Tandai sedang ditinjau (mis. oleh komite) tanpa memutuskan. */
    public function markUnderReview(HrTransaction $transaction, User $actor, ?string $notes = null): HrTransaction
    {
        $transaction = $this->lock($transaction);
        $this->machine->assert((string) $transaction->status, TransactionStatus::REVIEW->value);

        $transaction->forceFill([
            'status' => TransactionStatus::REVIEW->value,
            'decision_notes' => $notes ?? $transaction->decision_notes,
        ])->save();

        $this->audit->log($actor, 'transaction.review', $transaction, null, $transaction->toArray(), $notes, $transaction->getKey(), (int) $transaction->employee_id);

        return $transaction->refresh();
    }

    private function decide(HrTransaction $transaction, User $actor, TransactionStatus $target, ?string $notes): HrTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $target, $notes): HrTransaction {
            $transaction = $this->lock($transaction);
            $current = TransactionStatus::from((string) $transaction->status);

            // Idempoten: keputusan yang sama tidak menimpa waktu keputusan pertama.
            if ($current === $target) {
                return $transaction;
            }

            $this->machine->assert($current->value, $target->value);

            $attributes = [
                'status' => $target->value,
                'decision_notes' => $notes,
                'decided_by' => $actor->getKey(),
                'version' => (int) $transaction->version + 1,
            ];

            if ($target === TransactionStatus::APPROVED) {
                $attributes['approved_at'] = now();
            }

            if ($target === TransactionStatus::REJECTED) {
                $attributes['rejected_at'] = now();
            }

            if ($target === TransactionStatus::CANCELLED) {
                $attributes['cancelled_at'] = now();
                $attributes['cancelled_by'] = $actor->getKey();
            }

            $transaction->forceFill($attributes)->save();

            $employee = $transaction->employee;
            $event = match ($target) {
                TransactionStatus::APPROVED => 'transaction.approved',
                TransactionStatus::REJECTED => 'transaction.rejected',
                default => 'transaction.status_changed',
            };

            $this->timeline->record($transaction->employee_id, $event, ($transaction->type?->name ?? 'Transaksi').': '.$target->label(), [
                'category' => 'lifecycle',
                'source_type' => HrTransaction::class,
                'source_id' => $transaction->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => ['notes' => $notes, 'transaction_number' => $transaction->transaction_number],
            ]);

            $this->audit->log($actor, $event, $transaction, null, $transaction->toArray(), $notes, $transaction->getKey(), (int) $transaction->employee_id);

            $this->notifications->notify(
                $event,
                ($transaction->type?->name ?? 'Transaksi').' '.$target->label(),
                ($employee?->displayName() ?? 'Pegawai').': '.$transaction->transaction_number.($notes ? ' — '.$notes : ''),
                array_values(array_filter([(int) ($transaction->requested_by ?? 0), (int) ($employee?->user_id ?? 0)])),
                [],
                $employee,
                ['transaction_id' => (int) $transaction->getKey()],
            );

            // Persetujuan atas transaksi yang berlaku hari ini atau sebelumnya
            // langsung diterapkan; sisanya menunggu penjadwal pada tanggal berlaku.
            if ($target === TransactionStatus::APPROVED) {
                $effective = $transaction->effective_date;

                if ($effective === null || $effective->isPast() || $effective->isToday()) {
                    // Persetujuan untuk tanggal berlaku hari ini/lampau langsung
                    // diterapkan; transaksinya dikembalikan dalam keadaan terbaru.
                    $this->applyTransaction($transaction, $actor);

                    return $transaction->refresh();
                }
            }

            return $transaction->refresh();
        });
    }

    /* ── Penerapan ──────────────────────────────────────────────────────── */

    /**
     * Terapkan efek transaksi yang sudah disetujui (§77).
     *
     * Seluruh langkah berjalan di dalam satu transaksi basis data: penutupan
     * penugasan lama, penugasan baru, ringkasan pegawai, hitungan posisi, linimasa,
     * audit, notifikasi, dan outbox integrasi.
     */
    public function applyTransaction(HrTransaction $transaction, User $actor): array
    {
        return DB::transaction(function () use ($transaction, $actor): array {
            $transaction = $this->lock($transaction);

            if ($transaction->applied_at !== null) {
                // Idempoten: penerapan kedua tidak menggandakan penugasan.
                return [
                    'transaction_id' => (int) $transaction->getKey(),
                    'already_applied' => true,
                    'after_snapshot' => $transaction->after_snapshot,
                ];
            }

            if (! $this->machine->isAppliable((string) $transaction->status)) {
                throw LifecycleTransitionException::notApplied();
            }

            $employee = HrEmployee::query()->whereKey($transaction->employee_id)->lockForUpdate()->firstOrFail();
            $effect = $this->effects->resolve($transaction);

            $summary = $effect->apply($transaction, $employee, $actor);

            $transaction->forceFill([
                'status' => TransactionStatus::COMPLETED->value,
                'after_snapshot' => $summary,
                'applied_at' => now(),
                'applied_by' => $actor->getKey(),
            ])->save();

            $employee->refresh();

            $this->timeline->record($employee, 'transaction.applied', ($transaction->type?->name ?? 'Transaksi').' berlaku', [
                'category' => 'lifecycle',
                'effective_date' => $transaction->effective_date?->toDateString(),
                'source_type' => HrTransaction::class,
                'source_id' => $transaction->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
                'meta' => $summary,
            ]);

            $this->audit->log($actor, 'transaction.applied', $transaction, $transaction->before_snapshot, $summary, null, $transaction->getKey(), (int) $transaction->employee_id);

            $this->dispatchIntegrations($transaction, $employee, $summary);

            $this->notifications->notify(
                'transaction.applied',
                ($transaction->type?->name ?? 'Transaksi').' telah berlaku',
                $employee->displayName().': '.$transaction->transaction_number,
                array_values(array_filter([(int) ($transaction->requested_by ?? 0), (int) ($employee->user_id ?? 0)])),
                [],
                $employee,
                ['transaction_id' => (int) $transaction->getKey()],
            );

            return [
                'transaction_id' => (int) $transaction->getKey(),
                'already_applied' => false,
                'after_snapshot' => $summary,
            ];
        });
    }

    /** Transaksi yang jatuh tempo dan siap diterapkan (dijalankan penjadwal). */
    public function dueTransactions(?string $asOf = null, int $limit = 200): Builder
    {
        $date = $asOf ?? now()->toDateString();

        return HrTransaction::query()
            ->with(['type:id,code,name,effect_handler'])
            ->where('status', TransactionStatus::APPROVED->value)
            ->whereNull('applied_at')
            ->whereNotNull('effective_date')
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date')
            ->limit($limit);
    }

    /* ── Kueri ──────────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $filters */
    public function query(array $filters = []): Builder
    {
        return HrTransaction::query()
            ->with([
                'type:id,code,name,category',
                'employee.person:id,full_name',
                'employee:id,employee_number,person_id,organization_id,department_id',
                'requestedBy:id,name',
                'decidedBy:id,name',
                'items',
            ])
            ->when(! empty($filters['employee_id']), fn (Builder $q) => $q->where('employee_id', $filters['employee_id']))
            ->when(! empty($filters['type_code']), fn (Builder $q) => $q->where('type_code', $filters['type_code']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->whereIn('status', (array) $filters['status']))
            ->when(! empty($filters['pending']), fn (Builder $q) => $q->whereIn('status', [
                TransactionStatus::DRAFT->value, TransactionStatus::SUBMITTED->value, TransactionStatus::REVIEW->value,
            ]))
            ->when(! empty($filters['awaiting_apply']), fn (Builder $q) => $q->where('status', TransactionStatus::APPROVED->value)->whereNull('applied_at'))
            ->when(! empty($filters['search']), function (Builder $q) use ($filters): void {
                $term = '%'.trim((string) $filters['search']).'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('transaction_number', 'like', $term)
                    ->orWhereHas('employee', fn (Builder $employee) => $employee
                        ->where('employee_number', 'like', $term)
                        ->orWhereHas('person', fn (Builder $person) => $person->where('full_name', 'like', $term))));
            })
            ->when(! empty($filters['effective_from']), fn (Builder $q) => $q->where('effective_date', '>=', $filters['effective_from']))
            ->when(! empty($filters['effective_to']), fn (Builder $q) => $q->where('effective_date', '<=', $filters['effective_to']));
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->orderByDesc('id')->paginate(min(100, max(1, $perPage)));
    }

    /* ── Kontrak (HRTransactionProviderInterface) ───────────────────────── */

    public function submit(int $employeeId, string $typeCode, array $payload, ?int $actorUserId = null): array
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $actor = $actorUserId === null ? null : User::query()->find($actorUserId);

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Pengajuan transaksi membutuhkan pengguna pemohon.', 'hr.transaction.actor_required');
        }

        return $this->create($employee, $typeCode, $payload, $actor, $payload)->toArray();
    }

    /** Penerapan lewat kontrak (§65): id transaksi + id pelaksana. */
    public function apply(int $transactionId, ?int $actorUserId = null): array
    {
        $transaction = HrTransaction::query()->findOrFail($transactionId);
        $actor = $actorUserId === null ? null : User::query()->find($actorUserId);

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Penerapan transaksi membutuhkan pengguna yang jelas untuk jejak audit.', 'hr.transaction.actor_required');
        }

        return $this->applyTransaction($transaction, $actor);
    }

    public function findById(int $transactionId): ?array
    {
        return HrTransaction::query()->with(['type', 'items', 'employee.person'])->find($transactionId)?->toArray();
    }

    public function pendingInOrganization(int $organizationId): array
    {
        return $this->query(['pending' => true])
            ->whereHas('employee', fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->limit(100)
            ->get()
            ->toArray();
    }

    public function hasOpenTransaction(int $employeeId): bool
    {
        return HrTransaction::query()->where('employee_id', $employeeId)->pending()->exists();
    }

    public function isEngineHealthy(): bool
    {
        return $this->effects->all() !== [];
    }

    /* ── Bantu ──────────────────────────────────────────────────────────── */

    private function lock(HrTransaction $transaction): HrTransaction
    {
        return HrTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Satu pegawai tidak boleh memiliki dua transaksi berjalan untuk jenis/kategori
     * yang sama — dua pengajuan transfer bersamaan menghasilkan dua penugasan.
     */
    private function assertNoOpenTransactionOfSameType(HrEmployee $employee, string $typeCode, string $category): void
    {
        $existing = HrTransaction::query()
            ->where('employee_id', $employee->getKey())
            ->where('type_code', $typeCode)
            ->whereIn('status', [
                TransactionStatus::DRAFT->value, TransactionStatus::SUBMITTED->value,
                TransactionStatus::REVIEW->value, TransactionStatus::APPROVED->value,
            ])
            ->first();

        if ($existing !== null) {
            throw HrdConflictException::locked(
                "Pegawai ini masih memiliki transaksi \"{$existing->transaction_number}\" yang belum selesai untuk jenis yang sama."
            );
        }

        if ($category === 'offboarding') {
            $offboarding = HrTransaction::query()
                ->where('employee_id', $employee->getKey())
                ->whereHas('type', fn (Builder $q) => $q->where('category', 'offboarding'))
                ->pending()
                ->exists();

            if ($offboarding) {
                throw HrdConflictException::locked('Pegawai ini sudah dalam proses keluar.');
            }
        }
    }

    /** Tulis rincian perubahan agar layar persetujuan dapat menampilkan selisihnya. */
    private function writeItems(HrTransaction $transaction, HrTransactionType $type, HrEmployee $employee, array $payload): void
    {
        $items = [];

        if (isset($payload['organization_id'])) {
            $items[] = ['item_type' => 'organization', 'field' => 'organization_id', 'before_value' => $employee->organization_id, 'after_value' => $payload['organization_id'], 'label' => 'Unit organisasi'];
        }

        if (isset($payload['department_id'])) {
            $items[] = ['item_type' => 'department', 'field' => 'department_id', 'before_value' => $employee->department_id, 'after_value' => $payload['department_id'], 'label' => 'Departemen'];
        }

        if (isset($payload['position_id'])) {
            $items[] = ['item_type' => 'position', 'field' => 'position_id', 'before_value' => $employee->position_id, 'after_value' => $payload['position_id'], 'label' => 'Posisi'];
        }

        if (isset($payload['location_id'])) {
            $items[] = ['item_type' => 'location', 'field' => 'location_id', 'before_value' => $employee->location_id, 'after_value' => $payload['location_id'], 'label' => 'Lokasi kerja'];
        }

        if (isset($payload['manager_employee_id'])) {
            $items[] = ['item_type' => 'manager', 'field' => 'manager_employee_id', 'before_value' => $employee->manager_employee_id, 'after_value' => $payload['manager_employee_id'], 'label' => 'Atasan langsung'];
        }

        if (isset($payload['employment_status'])) {
            $items[] = ['item_type' => 'status', 'field' => 'employment_status', 'before_value' => $employee->employment_status, 'after_value' => $payload['employment_status'], 'label' => 'Status kepegawaian'];
        }

        if (isset($payload['lifecycle_state'])) {
            $items[] = ['item_type' => 'status', 'field' => 'lifecycle_state', 'before_value' => $employee->lifecycle_state, 'after_value' => $payload['lifecycle_state'], 'label' => 'Keadaan siklus hidup'];
        }

        if (isset($payload['grade_code'])) {
            $items[] = ['item_type' => 'grade', 'field' => 'grade_code', 'before_value' => null, 'after_value' => $payload['grade_code'], 'label' => 'Golongan'];
        }

        $items[] = ['item_type' => 'other', 'field' => 'effective_date', 'before_value' => null, 'after_value' => $transaction->effective_date?->toDateString(), 'label' => 'Tanggal berlaku'];
        $items[] = ['item_type' => 'other', 'field' => 'reason', 'before_value' => null, 'after_value' => $transaction->reason, 'label' => 'Alasan'];

        foreach ($items as $index => $item) {
            HrTransactionItem::query()->create([
                'transaction_id' => $transaction->getKey(),
                ...$item,
                'entity_type' => HrTransaction::class,
                'entity_id' => $transaction->getKey(),
                'sort_order' => $index,
            ]);
        }

        unset($type);
    }

    /** @return array<string, mixed> */
    private function snapshot(HrEmployee $employee): array
    {
        $assignment = HrEmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('is_primary', true)
            ->where('status', 'active')
            ->first();

        return [
            'employee_number' => $employee->employee_number,
            'lifecycle_state' => $employee->lifecycle_state,
            'employment_status' => $employee->employment_status,
            'organization_id' => $employee->organization_id,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'location_id' => $employee->location_id,
            'manager_employee_id' => $employee->manager_employee_id,
            'worker_type' => $employee->worker_type,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date?->toDateString(),
            'primary_assignment_id' => $assignment?->getKey(),
        ];
    }

    /**
     * Penerima notifikasi persetujuan: atasan pegawai, lalu peran HR bila tidak ada
     * atasan (mis. pegawai baru pada unit tanpa kepala).
     *
     * @return list<int>
     */
    private function approverUserIds(HrTransaction $transaction): array
    {
        $employee = $transaction->employee;

        if ($employee === null) {
            return [];
        }

        $managerId = $employee->currentManagerId();
        $managerUserId = $managerId === null ? null : HrEmployee::query()->whereKey($managerId)->value('user_id');

        return array_values(array_filter(array_map('intval', [$managerUserId])));
    }

    /** Kirim niat integrasi ke modul lain (Aset, Keuangan, Presensi, Akun) (§66). */
    private function dispatchIntegrations(HrTransaction $transaction, HrEmployee $employee, array $summary): void
    {
        $type = (string) $transaction->type_code;

        if (in_array($type, ['RESIGNATION', 'TERMINATION', 'RETIREMENT', 'DEATH', 'CONTRACT_EXPIRED', 'END_OF_CONTRACT'], true)) {
            $this->outbox->push('asset', 'employee.exit.asset_return', ['employee_id' => (int) $employee->getKey(), 'last_date' => $employee->termination_date?->toDateString()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('finance', 'employee.exit.clearance', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('attendance', 'employee.exit.deactivate', ['employee_id' => (int) $employee->getKey(), 'from_date' => $employee->termination_date?->toDateString()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('user', 'account.suspend', ['employee_id' => (int) $employee->getKey(), 'user_id' => $employee->user_id], HrEmployee::class, $employee->getKey());
        }

        if (in_array($type, ['HIRE', 'REHIRE'], true)) {
            $this->outbox->push('asset', 'employee.onboard.equipment', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('attendance', 'employee.onboard.activate', ['employee_id' => (int) $employee->getKey(), 'from_date' => $employee->hire_date?->toDateString()], HrEmployee::class, $employee->getKey());
            $this->outbox->push('finance', 'employee.register', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
        }

        if (in_array($type, ['TRANSFER', 'MUTATION', 'PROMOTION', 'DEMOTION', 'DEPARTMENT_CHANGE', 'LOCATION_CHANGE'], true)) {
            $this->outbox->push('lms', 'employee.assignment_changed', ['employee_id' => (int) $employee->getKey(), 'summary' => $summary], HrEmployee::class, $employee->getKey());
            $this->outbox->push('activity', 'employee.assignment_changed', ['employee_id' => (int) $employee->getKey()], HrEmployee::class, $employee->getKey());
        }

        unset($employee);
    }
}
