<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle\Effects;

use App\Models\User;
use App\Modules\Hrd\Application\Services\AssignmentService;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Application\Services\HrdAuditService;
use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmploymentRecord;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Efek masuk kerja dan bekerja kembali (§33, §38, §40).
 *
 * `HIRE` membuat catatan masa kerja dan memindahkan pegawai ke pra-kerja
 * (onboarding dimulai di luar handler ini, oleh layanan siklus hidup, karena ia
 * membutuhkan template).
 *
 * `REHIRE` adalah kasus yang paling sering salah ditangani: pegawai lama yang
 * kembali BUKAN pegawai baru. Orangnya sama (`person_id` tetap), nomor pegawai
 * tetap, tetapi masa kerja baru dicatat — sehingga total masa kerja dan laporan
 * terminasi tidak saling merusak.
 */
class HireEffect implements TransactionEffectInterface
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly EmployeeService $employees,
        private readonly HrdAuditService $audit,
    ) {}

    public function alias(): string
    {
        return 'hire';
    }

    public function supports(HrTransaction $transaction): bool
    {
        return in_array($transaction->type_code, ['HIRE', 'REHIRE'], true);
    }

    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array
    {
        $payload = $transaction->payload ?? [];
        $isRehire = $transaction->type_code === 'REHIRE';
        $startDate = $transaction->effective_date?->toDateString() ?? now()->toDateString();

        return DB::transaction(function () use ($transaction, $employee, $actor, $payload, $isRehire, $startDate): array {
            $record = HrEmploymentRecord::query()->create([
                'employee_id' => $employee->getKey(),
                'employment_number' => $employee->employee_number,
                'employment_type_code' => $payload['employment_type'] ?? $employee->employment_type ?? 'Permanent',
                'worker_type_code' => $payload['worker_type'] ?? $employee->worker_type,
                'start_date' => $startDate,
                'organization_id' => $payload['organization_id'] ?? $employee->organization_id,
                'department_id' => $payload['department_id'] ?? $employee->department_id,
                'position_id' => $payload['position_id'] ?? $employee->position_id,
                'status' => 'active',
                'notes' => $isRehire ? 'Masa kerja baru (bekerja kembali)' : 'Masa kerja pertama',
                'created_by' => $actor->getKey(),
            ]);

            $targetState = ($payload['start_probation'] ?? false)
                ? HrEmployee::LIFECYCLE_PROBATION
                : HrEmployee::LIFECYCLE_PREBOARDING;

            $employee->forceFill([
                'hire_date' => $isRehire ? $startDate : ($employee->hire_date ?? $startDate),
                'termination_date' => null,
                'exit_reason_code' => null,
                'termination_notes' => null,
                'archived_at' => null,
                'archive_reason' => null,
                'employment_type' => $payload['employment_type'] ?? $employee->employment_type,
                'worker_type' => $payload['worker_type'] ?? $employee->worker_type,
            ])->save();

            $this->employees->changeLifecycleState(
                $employee,
                $targetState,
                $actor,
                $targetState === HrEmployee::LIFECYCLE_PROBATION ? 'PROBATION' : 'PREBOARDING',
                $startDate,
                $transaction->reason,
                $transaction,
                skipTransitionCheck: true,
            );

            if (! empty($payload['assignment'])) {
                $this->assignments->assign($employee, [
                    ...$payload['assignment'],
                    'is_primary' => true,
                    'assignment_type' => 'primary',
                    'start_date' => $payload['assignment']['start_date'] ?? $startDate,
                ], $actor, $transaction);
            }

            $this->audit->log($actor, 'employment.hired', $record, null, $record->toArray(), $transaction->reason, $transaction->getKey(), (int) $employee->getKey());

            return [
                'employment_record_id' => (int) $record->getKey(),
                'lifecycle_state' => $targetState,
                'start_date' => $startDate,
                'is_rehire' => $isRehire,
            ];
        });
    }
}
