<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle\Effects;

use App\Models\User;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeAssignment;
use App\Modules\Hrd\Models\HrEmploymentRecord;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Efek pengakhiran hubungan kerja (§33, §40, §41, §59).
 *
 * Yang TIDAK dilakukan di sini — dan itu disengaja:
 *
 *  - **tidak menghapus apa pun.** Penugasan ditutup bertanggal, masa kerja
 *    diakhiri, akun ditangguhkan. Riwayat tetap utuh dan dapat diaudit.
 *  - **tidak langsung menjadi `inactive`** bila masih ada clearance yang menahan
 *    (`blocks_exit`). Pegawai berpindah ke `offboarding`; layanan offboarding yang
 *    memindahkannya ke `inactive` setelah semuanya bersih. Dengan begitu, "pegawai
 *    sudah keluar" dan "kewajibannya sudah selesai" tidak pernah tertukar.
 */
class SeparationEffect implements TransactionEffectInterface
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function alias(): string
    {
        return 'separation';
    }

    public function supports(HrTransaction $transaction): bool
    {
        return in_array($transaction->type_code, [
            'RESIGNATION', 'TERMINATION', 'RETIREMENT', 'CONTRACT_EXPIRED', 'DEATH', 'END_OF_CONTRACT',
        ], true);
    }

    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array
    {
        $payload = $transaction->payload ?? [];
        $effectiveDate = $transaction->effective_date?->toDateString() ?? now()->toDateString();
        $isImmediate = in_array($transaction->type_code, ['DEATH', 'TERMINATION'], true) && ($payload['immediate'] ?? false);

        return DB::transaction(function () use ($transaction, $employee, $actor, $payload, $effectiveDate, $isImmediate): array {
            $closedAssignments = 0;

            $assignments = HrEmployeeAssignment::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($assignments as $assignment) {
                $assignment->forceFill([
                    'end_date' => $effectiveDate,
                    'status' => 'ended',
                    'updated_by' => $actor->getKey(),
                    'notes' => trim((string) $assignment->notes.' | Berakhir: '.$transaction->transaction_number, ' |'),
                ])->save();

                $closedAssignments++;
            }

            HrEmploymentRecord::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', 'active')
                ->update([
                    'status' => 'ended',
                    'end_date' => $effectiveDate,
                    'termination_reason_code' => $transaction->reason_code,
                    'notes' => $transaction->reason,
                ]);

            $contractIds = HrContract::query()
                ->where('employee_id', $employee->getKey())
                ->whereIn('status', ['active', 'expiring', 'draft'])
                ->pluck('id');

            if ($contractIds->isNotEmpty()) {
                HrContract::query()->whereIn('id', $contractIds->all())->update([
                    'status' => 'terminated',
                    'end_date' => $effectiveDate,
                ]);
            }

            $employee->forceFill([
                'termination_date' => $effectiveDate,
                'exit_reason_code' => $transaction->reason_code ?? $payload['exit_reason_code'] ?? null,
                'termination_notes' => $transaction->reason,
            ])->save();

            $targetState = $isImmediate ? HrEmployee::LIFECYCLE_INACTIVE : HrEmployee::LIFECYCLE_OFFBOARDING;

            $updated = $this->employees->changeLifecycleState(
                $employee,
                $targetState,
                $actor,
                $this->statusCodeFor($transaction->type_code, $payload),
                $effectiveDate,
                $transaction->reason,
                $transaction,
                skipTransitionCheck: true,
            );

            if ($updated->user_id !== null && $isImmediate) {
                app(\App\Modules\Hrd\Domain\Contracts\UserAccountProviderInterface::class)
                    ->suspend((int) $updated->user_id, 'Pegawai berhenti: '.$transaction->transaction_number);
            }

            return [
                'lifecycle_to' => $updated->lifecycle_state,
                'termination_date' => $effectiveDate,
                'exit_reason_code' => $updated->exit_reason_code,
                'closed_assignments' => $closedAssignments,
                'terminated_contracts' => $contractIds->count(),
                'account_suspended' => $isImmediate && $updated->user_id !== null,
                'note' => $isImmediate
                    ? 'Hubungan kerja berakhir seketika.'
                    : 'Pegawai masuk proses offboarding; status tidak aktif hanya setelah clearance selesai.',
            ];
        });
    }

    private function statusCodeFor(string $typeCode, array $payload): string
    {
        return match ($typeCode) {
            'RESIGNATION' => 'RESIGNED',
            'RETIREMENT' => 'RETIRED',
            'DEATH' => 'DECEASED',
            'CONTRACT_EXPIRED', 'END_OF_CONTRACT' => 'CONTRACT_ENDED',
            default => (string) ($payload['employment_status'] ?? 'TERMINATED'),
        };
    }
}
