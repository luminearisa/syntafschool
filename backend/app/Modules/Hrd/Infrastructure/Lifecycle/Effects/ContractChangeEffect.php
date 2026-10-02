<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle\Effects;

use App\Models\User;
use App\Modules\Hrd\Application\Services\HrdAuditService;
use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmploymentRecord;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Efek kontrak: perpanjangan dan pembaruan (§36).
 *
 * Kontrak lama **tidak ditimpa**. Barisnya berpindah ke `renewed`/`terminated`,
 * dan kontrak baru menunjuk kontrak yang digantikannya (`supersedes_contract_id`).
 * Dengan begitu pertanyaan "berapa kali kontrak ini diperpanjang, dan dengan syarat
 * apa?" terjawab dari data, bukan dari ingatan admin.
 */
class ContractChangeEffect implements TransactionEffectInterface
{
    public function __construct(private readonly HrdAuditService $audit) {}

    public function alias(): string
    {
        return 'contract_change';
    }

    public function supports(HrTransaction $transaction): bool
    {
        return in_array($transaction->type_code, [
            'CONTRACT_EXTENSION', 'CONTRACT_RENEWAL', 'CONTRACT_SIGNED', 'CONTRACT_TERMINATION',
        ], true);
    }

    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array
    {
        $payload = $transaction->payload ?? [];
        $effectiveDate = $transaction->effective_date?->toDateString() ?? now()->toDateString();

        return DB::transaction(function () use ($transaction, $employee, $actor, $payload, $effectiveDate): array {
            $current = HrContract::query()
                ->where('employee_id', $employee->getKey())
                ->whereIn('status', ['active', 'expiring'])
                ->orderByDesc('start_date')
                ->lockForUpdate()
                ->first();

            if ($transaction->type_code === 'CONTRACT_TERMINATION') {
                if ($current === null) {
                    throw HrdValidationException::forField('employee_id', 'Tidak ada kontrak aktif yang dapat diakhiri.', 'hr.contract.none_active');
                }

                $before = $current->toArray();
                $current->forceFill(['status' => 'terminated', 'end_date' => $effectiveDate])->save();

                $this->audit->log($actor, 'contract.terminated', $current, $before, $current->toArray(), $transaction->reason, $transaction->getKey(), (int) $employee->getKey());

                return [
                    'contract_id' => (int) $current->getKey(),
                    'status' => 'terminated',
                    'end_date' => $effectiveDate,
                ];
            }

            $number = $payload['contract_number']
                ?? app(\App\Modules\Hrd\Application\Support\EmployeeNumberGenerator::class)->next('contract', [
                    'organization_id' => $employee->organization_id,
                ]);

            $employmentRecord = HrEmploymentRecord::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', 'active')
                ->orderByDesc('start_date')
                ->first();

            $newContract = HrContract::query()->create([
                'contract_number' => $number,
                'employee_id' => $employee->getKey(),
                'contract_type_id' => $payload['contract_type_id'] ?? $current?->contract_type_id ?? $this->defaultContractTypeId(),
                'employment_record_id' => $employmentRecord?->getKey() ?? $current?->employment_record_id,
                'position_id' => $payload['position_id'] ?? $employee->position_id,
                'supersedes_contract_id' => $current?->getKey(),
                'start_date' => $payload['start_date'] ?? $effectiveDate,
                'end_date' => $payload['end_date'] ?? null,
                'renewal_date' => $payload['renewal_date'] ?? null,
                'signed_date' => $payload['signed_date'] ?? null,
                'status' => ($payload['signed_date'] ?? null) === null ? 'draft' : 'active',
                'is_signed' => ($payload['signed_date'] ?? null) !== null,
                'document_id' => $payload['document_id'] ?? null,
                'notes' => $payload['notes'] ?? $transaction->reason,
                'created_by' => $actor->getKey(),
            ]);

            if ($current !== null) {
                $before = $current->toArray();
                $current->forceFill([
                    'status' => $transaction->type_code === 'CONTRACT_RENEWAL' ? 'renewed' : 'expiring',
                    'end_date' => $payload['previous_end_date'] ?? $current->end_date,
                ])->save();

                $this->audit->log($actor, 'contract.superseded', $current, $before, $current->toArray(), $transaction->reason, $transaction->getKey(), (int) $employee->getKey());
            }

            $this->audit->log($actor, 'contract.created', $newContract, null, $newContract->toArray(), $transaction->reason, $transaction->getKey(), (int) $employee->getKey());

            if ($employee->lifecycle_state === HrEmployee::LIFECYCLE_INACTIVE) {
                $employee->forceFill(['termination_date' => null, 'exit_reason_code' => null])->save();
            }

            return [
                'contract_id' => (int) $newContract->getKey(),
                'contract_number' => $newContract->contract_number,
                'supersedes' => $current?->contract_number,
                'start_date' => $newContract->start_date?->toDateString(),
                'end_date' => $newContract->end_date?->toDateString(),
                'status' => $newContract->status,
            ];
        });
    }

    private function defaultContractTypeId(): int
    {
        $id = \App\Modules\Hrd\Models\HrContractType::query()->where('code', 'PKWT')->value('id')
            ?? \App\Modules\Hrd\Models\HrContractType::query()->orderBy('id')->value('id');

        if ($id === null) {
            throw HrdValidationException::forField('contract_type_id', 'Jenis kontrak belum diisi pada master.', 'hr.contract.type_missing');
        }

        return (int) $id;
    }
}
