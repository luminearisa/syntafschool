<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\ParentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EloquentParentGateway implements ParentGatewayInterface
{
    use GatewaySupport;

    /** Modul lain (Activity) menyediakan implementasi lebih kaya bila terpasang. */
    protected function external(): ?object
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\ParentProviderInterface');
    }

    public function guardiansOf(int $studentId): array
    {
        if ($external = $this->external()) {
            try {
                $externalResult = (array) $external->guardiansOf($studentId);
                if ($externalResult !== []) {
                    return $externalResult;
                }
            } catch (\Throwable) {
                // lanjut ke pembacaan langsung
            }
        }

        if (! $this->has('student_guardians') || ! $this->has('guardians')) {
            return [];
        }

        $rows = DB::table('student_guardians')
            ->join('guardians', 'guardians.id', '=', 'student_guardians.guardian_id')
            ->where('student_guardians.student_id', $studentId)
            ->get();

        return array_values(array_map(fn ($row): array => [
            'guardian_id' => (int) $row->guardian_id,
            'full_name' => $row->full_name ?? null,
            'phone' => $row->phone ?? null,
            'email' => $row->email ?? null,
            'is_primary' => (bool) ($row->is_primary ?? false),
            'relation_id' => $row->guardian_relation_id ?? null,
        ], $rows->all()));
    }

    public function userIdsFor(int $studentId): array
    {
        if ($external = $this->external()) {
            try {
                $externalUsers = array_values(array_unique(array_map('intval', (array) $external->userIdsFor($studentId))));
                if ($externalUsers !== []) {
                    return $externalUsers;
                }
            } catch (\Throwable) {
            }
        }

        if (! $this->has('users') || ! $this->has('student_guardians')) {
            return [];
        }

        $guardians = $this->guardiansOf($studentId);
        $userColumns = $this->columns('users');
        $emails = array_values(array_filter(array_column($guardians, 'email')));
        $phones = array_values(array_filter(array_column($guardians, 'phone')));
        if ($emails === [] && $phones === []) {
            return [];
        }

        $query = DB::table('users');
        $query->where(function ($q) use ($emails, $phones, $userColumns): void {
            if ($emails !== []) {
                $q->whereIn('email', $emails);
            }
            if ($phones !== [] && in_array('phone', $userColumns, true)) {
                $q->orWhereIn('phone', $phones);
            }
        });

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }

    public function childrenOfUser(int $userId): array
    {
        if ($external = $this->external()) {
            try {
                $children = array_values(array_map('intval', (array) $external->childrenOfUser($userId)));
                if ($children !== []) {
                    return $children;
                }
            } catch (\Throwable) {
            }
        }

        if (! $this->has('users') || ! $this->has('student_guardians') || ! $this->has('guardians')) {
            return [];
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) {
            return [];
        }

        $guardianQuery = DB::table('guardians')->where(function ($q) use ($user): void {
            $q->where('email', $user->email ?? '__none__');
            if (Schema::hasColumn('guardians', 'phone') && ! empty($user->phone ?? null)) {
                $q->orWhere('phone', $user->phone);
            }
        });
        $guardianIds = $guardianQuery->pluck('id')->all();
        if ($guardianIds === []) {
            return [];
        }

        return array_values(array_map('intval', DB::table('student_guardians')
            ->whereIn('guardian_id', $guardianIds)
            ->pluck('student_id')->all()));
    }

    public function canActFor(int $userId, int $studentId): bool
    {
        if ($external = $this->external()) {
            try {
                if ((bool) $external->canActFor($userId, $studentId)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return in_array($studentId, $this->childrenOfUser($userId), true);
    }

    public function available(): bool
    {
        return $this->has('student_guardians') && $this->has('guardians');
    }
}
