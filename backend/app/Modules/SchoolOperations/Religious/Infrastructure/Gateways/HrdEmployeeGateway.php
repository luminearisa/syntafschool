<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\EmployeeGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pegawai/guru dari HRD (§16, §36). Imam, muadzin, khatib, dan pengajar
 * agama selalu merujuk ke pegawai/siswa yang sudah ada — bukan master baru.
 */
class HrdEmployeeGateway implements EmployeeGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Hrd\\Domain\\Contracts\\EmployeeProviderInterface');
    }

    public function snapshot(int $employeeId): ?array
    {
        if ($provider = $this->provider()) {
            try {
                if (method_exists($provider, 'find')) {
                    $found = $provider->find($employeeId);
                    if (is_array($found)) {
                        return $found;
                    }
                }
            } catch (\Throwable) {
            }
        }

        if (! $this->has('hr_employees')) {
            return null;
        }

        $employee = $this->row('hr_employees', $employeeId);
        if (! $employee) {
            return null;
        }

        $person = null;
        if (! empty($employee['person_id']) && $this->has('hr_people')) {
            $person = $this->row('hr_people', (int) $employee['person_id']);
        }

        $name = $person['full_name'] ?? ($person['name'] ?? null)
            ?? ($employee['full_name'] ?? ($employee['name'] ?? null));

        return [
            'id' => (int) $employee['id'],
            'employee_number' => $employee['employee_number'] ?? null,
            'full_name' => $name,
            'user_id' => $employee['user_id'] ?? ($person['user_id'] ?? null),
            'position_id' => $employee['position_id'] ?? null,
            'organization_id' => $employee['organization_id'] ?? null,
            'department_id' => $employee['department_id'] ?? null,
            'campus_id' => $employee['campus_id'] ?? null,
            'worker_type' => $employee['worker_type'] ?? null,
            'employment_status' => $employee['employment_status'] ?? null,
            'gender' => $person['gender'] ?? ($employee['gender'] ?? null),
            'active' => in_array(strtoupper((string) ($employee['employment_status'] ?? 'ACTIVE')),
                ['ACTIVE', 'PROBATION', 'CONTRACT'], true),
            'source' => 'hr_employees',
        ];
    }

    public function snapshots(array $employeeIds): array
    {
        $result = [];
        foreach (array_unique(array_filter($employeeIds)) as $id) {
            $snapshot = $this->snapshot((int) $id);
            if ($snapshot) {
                $result[(int) $id] = $snapshot;
            }
        }

        return $result;
    }

    public function byUser(int $userId): ?array
    {
        if (! $this->has('hr_employees') || ! in_array('user_id', $this->columns('hr_employees'), true)) {
            return null;
        }

        $row = DB::table('hr_employees')->where('user_id', $userId)->orderByDesc('id')->first();

        return $row ? $this->snapshot((int) $row->id) : null;
    }

    public function search(string $term, int $limit = 20): array
    {
        if (! $this->has('hr_employees') || $term === '') {
            return [];
        }

        $query = DB::table('hr_employees');
        if ($this->has('hr_people')) {
            $query->join('hr_people', 'hr_people.id', '=', 'hr_employees.person_id')
                ->where(function ($q) use ($term): void {
                    $q->where('hr_people.full_name', 'like', '%'.$term.'%')
                        ->orWhere('hr_people.name', 'like', '%'.$term.'%');
                })
                ->select('hr_employees.id')
                ->limit($limit);

            return array_values(array_filter(array_map(
                fn ($row): ?array => $this->snapshot((int) $row->id),
                $query->get()->all()
            )));
        }

        if (in_array('full_name', $this->columns('hr_employees'), true)) {
            $query->where('full_name', 'like', '%'.$term.'%');
        }

        return array_values(array_filter(array_map(
            fn ($row): ?array => $this->snapshot((int) $row->id),
            $query->limit($limit)->get()->all()
        )));
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->has('hr_employees');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul HRD/kepegawaian tidak tersedia pada instalasi ini.';
    }
}
