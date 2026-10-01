<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Models\User;
use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Enums\ApproverSource;
use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;
use Spatie\Permission\Models\Role;

/**
 * Resolver berbasis peran sistem (§24).
 *
 * Mengambil seluruh pengguna aktif yang memegang peran tersebut. Peran sistem
 * (RBAC) sengaja dipakai alih-alih daftar nama: mutasi pejabat cukup dilakukan
 * dengan memindahkan peran, tanpa menyentuh definisi workflow.
 */
final class RoleApproverResolver extends BaseApproverResolver
{
    public function __construct(
        private readonly DelegationService $delegations,
    ) {
    }

    public function alias(): string
    {
        return 'role';
    }

    public function description(): string
    {
        return 'Semua pengguna aktif dengan peran tertentu (mis. FINANCE_MANAGER).';
    }

    public function requiredSpec(): array
    {
        return ['role' => 'string|array'];
    }

    public function resolve(WorkflowContext $context, array $spec = []): array
    {
        $roles = $this->listValue($spec, 'role');
        $resolved = [];

        foreach ($roles as $roleName) {
            $role = Role::query()
                ->where('name', (string) $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($role === null) {
                continue;
            }

            $users = User::query()
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->role($role->name)
                ->pluck('id');

            foreach ($users as $userId) {
                $delegation = $this->delegations->activeDelegationFor((int) $userId, $context);

                if ($delegation !== null) {
                    $resolved[] = new ResolvedApprover(
                        userId: (int) $delegation->delegate_id,
                        source: ApproverSource::DELEGATION,
                        resolver: $this->alias(),
                        spec: $spec + ['resolved_role' => (string) $roleName],
                        originalUserId: (int) $userId,
                        delegationId: (int) $delegation->id,
                        isDelegate: true,
                    );

                    continue;
                }

                $resolved[] = new ResolvedApprover(
                    userId: (int) $userId,
                    source: ApproverSource::ROLE,
                    resolver: $this->alias(),
                    spec: $spec + ['resolved_role' => (string) $roleName],
                );
            }
        }

        return $resolved;
    }
}
