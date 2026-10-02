<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle\Effects;

use App\Models\User;
use App\Modules\Hrd\Application\Services\EmployeeService;
use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrProbation;
use App\Modules\Hrd\Models\HrTransaction;

/**
 * Efek perubahan status: cuti, kembali dari cuti, penangguhan, pengangkatan tetap,
 * dan keputusan masa percobaan (§18, §33, §37, §68).
 *
 * Tiga hal yang sengaja eksplisit:
 *  - **Perpanjangan masa percobaan** memperbarui baris `hr_probations` (bukan
 *    membuat baris baru), sehingga riwayat percobaan tetap satu.
 *  - **Cuti** tidak mengakhiri penugasan; pegawai tetap memegang kursinya.
 *  - **Penangguhan** menonaktifkan akun (bila modul pengguna tersedia) tetapi
 *    tidak menghapus data — pegawai yang kembali harus menemukan riwayatnya utuh.
 */
class StatusChangeEffect implements TransactionEffectInterface
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function alias(): string
    {
        return 'status_change';
    }

    public function supports(HrTransaction $transaction): bool
    {
        return in_array($transaction->type_code, [
            'STATUS_CHANGE', 'LEAVE', 'RETURN_FROM_LEAVE', 'SUSPENSION', 'CONFIRMATION',
            'PROBATION_EXTENSION', 'PROBATION_PASSED', 'PROBATION_FAILED',
        ], true);
    }

    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array
    {
        $payload = $transaction->payload ?? [];
        $effectiveDate = $transaction->effective_date?->toDateString() ?? now()->toDateString();

        return match ($transaction->type_code) {
            'LEAVE' => $this->transition($transaction, $employee, $actor, HrEmployee::LIFECYCLE_LEAVE, 'LEAVE', $effectiveDate),
            'SUSPENSION' => $this->transition($transaction, $employee, $actor, HrEmployee::LIFECYCLE_SUSPENDED, 'SUSPENDED', $effectiveDate),
            'RETURN_FROM_LEAVE' => $this->transition(
                $transaction,
                $employee,
                $actor,
                ($payload['return_to'] ?? 'active') === 'probation' ? HrEmployee::LIFECYCLE_PROBATION : HrEmployee::LIFECYCLE_ACTIVE,
                ($payload['return_to'] ?? 'active') === 'probation' ? 'PROBATION' : 'ACTIVE',
                $effectiveDate,
            ),
            'CONFIRMATION', 'PROBATION_PASSED' => $this->probationDecision($transaction, $employee, $actor, 'passed', $effectiveDate),
            'PROBATION_FAILED' => $this->probationDecision($transaction, $employee, $actor, 'failed', $effectiveDate),
            'PROBATION_EXTENSION' => $this->probationDecision($transaction, $employee, $actor, 'extended', $effectiveDate),
            default => $this->transition(
                $transaction,
                $employee,
                $actor,
                (string) ($payload['lifecycle_state'] ?? HrEmployee::LIFECYCLE_ACTIVE),
                (string) ($payload['employment_status'] ?? 'ACTIVE'),
                $effectiveDate,
            ),
        };
    }

    /** @return array<string, mixed> */
    private function transition(HrTransaction $transaction, HrEmployee $employee, User $actor, string $state, string $statusCode, string $effectiveDate): array
    {
        $before = (string) $employee->lifecycle_state;

        $updated = $this->employees->changeLifecycleState(
            $employee,
            $state,
            $actor,
            $statusCode,
            $effectiveDate,
            $transaction->reason,
            $transaction,
        );

        return [
            'lifecycle_from' => $before,
            'lifecycle_to' => $updated->lifecycle_state,
            'employment_status' => $updated->employment_status,
            'effective_date' => $effectiveDate,
        ];
    }

    /** @return array<string, mixed> */
    private function probationDecision(HrTransaction $transaction, HrEmployee $employee, User $actor, string $decision, string $effectiveDate): array
    {
        $probation = HrProbation::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', ['pending', 'active', 'extended'])
            ->orderByDesc('start_date')
            ->first();

        if ($probation === null) {
            throw HrdValidationException::forField(
                'employee_id',
                'Pegawai ini tidak memiliki masa percobaan yang sedang berjalan.',
                'hr.probation.not_found',
            );
        }

        $payload = $transaction->payload ?? [];

        $probation->forceFill([
            'status' => $decision,
            'review_date' => $payload['review_date'] ?? $probation->review_date,
            'review_result' => $payload['review_result'] ?? $transaction->reason,
            'extended_to' => $decision === 'extended' ? ($payload['extended_to'] ?? null) : $probation->extended_to,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ])->save();

        $targetState = match ($decision) {
            'passed' => HrEmployee::LIFECYCLE_CONFIRMED,
            'failed' => HrEmployee::LIFECYCLE_OFFBOARDING,
            default => HrEmployee::LIFECYCLE_PROBATION,
        };

        $updated = $this->employees->changeLifecycleState(
            $employee,
            $targetState,
            $actor,
            match ($decision) {
                'passed' => 'PERMANENT',
                'failed' => 'PROBATION_FAILED',
                default => 'PROBATION',
            },
            $effectiveDate,
            $transaction->reason,
            $transaction,
            skipTransitionCheck: true,
        );

        if ($decision === 'passed') {
            $updated->forceFill(['confirmation_date' => $effectiveDate, 'probation_end_date' => $effectiveDate])->save();
        }

        return [
            'probation_id' => (int) $probation->getKey(),
            'decision' => $decision,
            'extension_end' => $probation->extended_to?->toDateString(),
            'lifecycle_to' => $updated->lifecycle_state,
            'effective_date' => $effectiveDate,
        ];
    }
}
