<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\ParentProviderInterface;
use App\Modules\LMS\Models\LmsGuardian;

/**
 * Standalone Mode: tautan wali ↔ anak disimpan LMS sendiri (`lms_guardians`).
 */
class StandaloneParentProvider implements ParentProviderInterface
{
    public function key(): string
    {
        return 'standalone';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function guardiansForStudent(int $studentId): array
    {
        return LmsGuardian::query()
            ->with('user:id,name,email')
            ->where('lms_learner_id', $studentId)
            ->get()
            ->map(fn (LmsGuardian $link) => [
                'user_id' => $link->lms_user_id !== null ? (int) $link->lms_user_id : null,
                'name' => (string) ($link->user->name ?? 'Wali '.$link->id),
                'email' => $link->user->email ?? null,
                'phone' => $link->phone ?? null,
                'relation' => $link->relation ?? null,
                'is_primary' => (bool) $link->is_primary,
                'source' => 'standalone',
            ])->all();
    }

    public function guardianUserIdsForStudent(int $studentId): array
    {
        return LmsGuardian::query()
            ->where('lms_learner_id', $studentId)
            ->whereNotNull('lms_user_id')
            ->pluck('lms_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function studentIdsForGuardian(int $userId): array
    {
        return LmsGuardian::query()
            ->where('lms_user_id', $userId)
            ->whereNotNull('lms_learner_id')
            ->pluck('lms_learner_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function guards(int $userId, int $studentId): bool
    {
        return LmsGuardian::query()
            ->where('lms_user_id', $userId)
            ->where('lms_learner_id', $studentId)
            ->exists();
    }
}
