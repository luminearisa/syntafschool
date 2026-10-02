<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\EmployeeGatewayInterface;
use Illuminate\Support\Facades\DB;

class HrdEmployeeGateway implements EmployeeGatewayInterface
{
    use GatewaySupport;

    public function find(int $employeeId): ?array
    {
        if (! $this->has('hr_employees')) {
            return $this->fromModule($employeeId);
        }

        $employee = $this->row('hr_employees', $employeeId);
        if (! $employee) {
            return null;
        }

        $person = isset($employee['person_id']) ? $this->row('hr_people', (int) $employee['person_id']) : null;
        $user = isset($employee['user_id']) && $employee['user_id'] ? $this->row('users', (int) $employee['user_id']) : null;

        $external = [];
        try {
            $external = (array) ($this->delegate('App\Modules\Hrd\Domain\Contracts\EmployeeProviderInterface')?->findById($employeeId) ?? []);
        } catch (\Throwable) {
            $external = [];
        }

        return array_merge([
            'id' => (int) $employee['id'],
            'employee_number' => $employee['employee_number'] ?? null,
            'full_name' => $person['full_name'] ?? ($user->name ?? null),
            'phone' => $person['phone'] ?? ($person['mobile'] ?? null),
            'email' => $person['work_email'] ?? ($user->email ?? null),
            'user_id' => $employee['user_id'] ?? null,
            'organization_id' => $employee['organization_id'] ?? null,
            'department_id' => $employee['department_id'] ?? null,
            'position_id' => $employee['position_id'] ?? null,
            'employment_status' => $employee['employment_status'] ?? null,
            'active' => in_array(strtoupper((string) ($employee['employment_status'] ?? '')), ['ACTIVE', 'PROBATION', 'CONTRACT'], true),
            'source' => 'hr_employees',
        ], array_intersect_key($external, ['full_name' => null, 'phone' => null, 'email' => null, 'position' => null]));
    }

    /** Cadangan: kontrak HRD saja (bila tabel pegawai tidak ada pada instalasi ini). */
    protected function fromModule(int $employeeId): ?array
    {
        $external = $this->delegate('App\Modules\Hrd\Domain\Contracts\EmployeeProviderInterface');
        if (! $external) {
            return null;
        }

        try {
            $result = $external->findById($employeeId);
        } catch (\Throwable) {
            return null;
        }

        return is_array($result) && $result !== [] ? $result + ['source' => 'hrd'] : null;
    }

    public function findByUser(int $userId): ?array
    {
        $external = $this->delegate('App\Modules\Hrd\Domain\Contracts\EmployeeProviderInterface');
        if ($external) {
            try {
                $result = $external->findByUserId($userId);
                if (is_array($result) && $result !== []) {
                    return $result + ['source' => 'hrd'];
                }
            } catch (\Throwable) {
            }
        }

        if (! $this->has('hr_employees')) {
            return null;
        }

        $row = DB::table('hr_employees')->where('user_id', $userId)->first();

        return $row ? $this->find((int) $row->id) : null;
    }

    public function isActive(int $employeeId, ?string $onDate = null): bool
    {
        $employee = $this->find($employeeId);

        return (bool) ($employee['active'] ?? false);
    }

    public function available(): bool
    {
        return $this->has('hr_employees');
    }
}
