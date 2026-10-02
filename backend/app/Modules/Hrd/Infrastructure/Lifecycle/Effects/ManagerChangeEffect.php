<?php

namespace App\Modules\Hrd\Infrastructure\Lifecycle\Effects;

use App\Models\User;
use App\Modules\Hrd\Application\Services\AssignmentService;
use App\Modules\Hrd\Domain\Contracts\TransactionEffectInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Efek perubahan atasan (§21, §33).
 *
 * Dipisahkan dari perubahan penugasan karena akibatnya berbeda: mengganti atasan
 * langsung mengubah **siapa yang berhak menyetujui** pengajuan pegawai tersebut
 * pada Workflow Engine. Karena itu hubungan lama ditutup dengan tanggal yang
 * jelas, bukan ditimpa.
 */
class ManagerChangeEffect implements TransactionEffectInterface
{
    public function __construct(private readonly AssignmentService $assignments) {}

    public function alias(): string
    {
        return 'manager_change';
    }

    public function supports(HrTransaction $transaction): bool
    {
        return in_array($transaction->type_code, ['MANAGER_CHANGE', 'SUPERVISOR_CHANGE'], true);
    }

    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array
    {
        $payload = $transaction->payload ?? [];
        $effectiveDate = $transaction->effective_date?->toDateString() ?? now()->toDateString();

        return DB::transaction(function () use ($transaction, $employee, $actor, $payload, $effectiveDate): array {
            $managerId = $payload['manager_employee_id'] ?? null;
            $manager = $managerId === null ? null : HrEmployee::query()->find($managerId);

            if ($managerId !== null && $manager === null) {
                throw HrdValidationException::forField('manager_employee_id', 'Pegawai yang dipilih sebagai atasan tidak ditemukan.', 'hr.manager.missing');
            }

            $before = $this->assignments->managersOf($employee)->map(fn ($relation) => [
                'manager_employee_id' => (int) $relation->manager_employee_id,
                'relationship_type' => $relation->relationship_type,
            ])->all();

            $relation = $this->assignments->setManager($employee, $manager, [
                'relationship_type' => $payload['relationship_type'] ?? 'direct',
                'start_date' => $effectiveDate,
                'end_date' => $payload['end_date'] ?? null,
                'is_primary' => $payload['is_primary'] ?? true,
                'notes' => $transaction->reason,
            ], $actor, $transaction);

            return [
                'previous_managers' => $before,
                'manager_employee_id' => $manager?->getKey(),
                'manager_name' => $manager?->displayName(),
                'relationship_type' => $relation?->relationship_type,
                'effective_date' => $effectiveDate,
                'note' => 'Perubahan atasan ikut mengubah resolusi approver pada Workflow Engine.',
            ];
        });
    }
}
