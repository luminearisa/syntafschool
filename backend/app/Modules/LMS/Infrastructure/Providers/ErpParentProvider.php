<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\LMS\Domain\Contracts\ParentProviderInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated Mode: wali murid dibaca dari ERP (`guardians`,
 * `student_guardians`, akun `ppdb_parent_accounts`).
 *
 * Read-only: LMS tidak pernah menulis tabel wali ERP (§7).
 */
class ErpParentProvider implements ParentProviderInterface
{
    public function key(): string
    {
        return 'erp';
    }

    public function isAvailable(): bool
    {
        return Schema::hasTable('guardians') && Schema::hasTable('student_guardians');
    }

    public function guardiansForStudent(int $studentId): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $guardians = Guardian::query()
            ->whereHas('students', fn (Builder $q) => $q->where('students.id', $studentId))
            ->get();

        if ($guardians->isEmpty()) {
            return [];
        }

        $accounts = $this->accountMap($guardians->pluck('id')->all());
        $primaryIds = $this->primaryGuardianIds($studentId);

        return $guardians->map(fn (Guardian $guardian) => [
            'user_id' => $accounts[$guardian->id] ?? null,
            'name' => (string) ($guardian->full_name ?? $guardian->name ?? 'Wali '.$guardian->id),
            'email' => $guardian->email,
            'phone' => $guardian->phone ?? null,
            'relation' => $guardian->relation ?? null,
            'is_primary' => in_array((int) $guardian->id, $primaryIds, true),
            'source' => 'erp',
        ])->all();
    }

    public function guardianUserIdsForStudent(int $studentId): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $guardian) => $guardian['user_id'],
            $this->guardiansForStudent($studentId),
        ))));
    }

    public function studentIdsForGuardian(int $userId): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $guardianIds = $this->guardianIdsForUser($userId);

        if ($guardianIds === []) {
            return [];
        }

        return Student::query()
            ->whereHas('guardians', fn (Builder $q) => $q->whereIn('guardians.id', $guardianIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function guards(int $userId, int $studentId): bool
    {
        return in_array($studentId, $this->studentIdsForGuardian($userId), true);
    }

    /**
     * Akun pengguna wali: tabel `ppdb_parent_accounts` (bila modul PPDB ada)
     * lalu jatuh ke pencocokan email — sama seperti `ErpStudentProvider`.
     *
     * @param  list<int>  $guardianIds
     * @return array<int, int|null>
     */
    private function accountMap(array $guardianIds): array
    {
        $map = [];

        if (Schema::hasTable('ppdb_parent_accounts')) {
            $rows = User::query()
                ->join('ppdb_parent_accounts as ppa', 'ppa.user_id', '=', 'users.id')
                ->whereIn('ppa.guardian_id', $guardianIds)
                ->whereNotNull('ppa.guardian_id')
                ->get(['ppa.guardian_id', 'users.id as user_id', 'users.email']);

            foreach ($rows as $row) {
                $map[(int) $row->guardian_id] ??= (int) $row->user_id;
            }
        }

        $missing = array_values(array_diff(array_map('intval', $guardianIds), array_keys($map)));

        if ($missing !== []) {
            $emails = Guardian::query()->whereIn('id', $missing)->pluck('email', 'id');

            if ($emails->filter()->isNotEmpty()) {
                $users = User::query()->whereIn('email', $emails->filter()->values())->pluck('id', 'email');

                foreach ($emails as $guardianId => $email) {
                    if ($email !== null && $users->has($email)) {
                        $map[(int) $guardianId] = (int) $users->get($email);
                    }
                }
            }
        }

        return $map;
    }

    /** @return list<int> */
    private function guardianIdsForUser(int $userId): array
    {
        $ids = [];

        if (Schema::hasTable('ppdb_parent_accounts')) {
            $ids = User::query()
                ->join('ppdb_parent_accounts as ppa', 'ppa.user_id', '=', 'users.id')
                ->where('users.id', $userId)
                ->whereNotNull('ppa.guardian_id')
                ->pluck('ppa.guardian_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        $email = User::query()->whereKey($userId)->value('email');

        if ($email === null) {
            return [];
        }

        return Guardian::query()
            ->where('email', $email)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function primaryGuardianIds(int $studentId): array
    {
        return Student::query()
            ->whereKey($studentId)
            ->first()?->guardians()
            ->wherePivot('is_primary', true)
            ->pluck('guardians.id')
            ->map(fn ($id) => (int) $id)
            ->all() ?? [];
    }
}
