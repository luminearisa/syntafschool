<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\ParentProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wali santri: delegasi ke provider modul Activity bila terpasang, fallback ke
 * pembacaan `student_guardians`/`guardians`/`users` langsung. Dipakai untuk
 * isolasi akses portal wali (Rule 20) dan tujuan notifikasi (§45).
 */
class EloquentParentGateway implements ParentProviderInterface
{
    use GatewaySupport;

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
        $emails = array_values(array_filter(array_column($guardians, 'email')));
        $phones = array_values(array_filter(array_column($guardians, 'phone')));
        if ($emails === [] && $phones === []) {
            return [];
        }

        $userColumns = $this->columns('users');
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

        $guardianIds = DB::table('guardians')->where(function ($q) use ($user): void {
            $q->where('email', $user->email ?? '__none__');
            if (Schema::hasColumn('guardians', 'phone') && ! empty($user->phone ?? null)) {
                $q->orWhere('phone', $user->phone);
            }
        })->pluck('id')->all();

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
