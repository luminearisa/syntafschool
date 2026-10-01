<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\CounselingProviderInterface;
use App\Modules\Activity\Domain\Contracts\ParentProviderInterface;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Models\ActivityAchievement;
use App\Modules\Activity\Models\ActivityMembership;

/**
 * BK — pembatas, bukan jembatan data pribadi (§60).
 *
 * Yang boleh dilihat BK hanyalah **sinyal perkembangan** yang sudah menjadi data
 * bersama: keikutsertaan, kepemimpinan, prestasi, dan konsistensi kehadiran.
 * Catatan konseling tidak pernah melewati modul ini, dan modul ini tidak
 * menyediakan jalan untuk membacanya.
 */
class NullCounselingProvider implements CounselingProviderInterface
{
    public function __construct(private readonly ParentProviderInterface $parents) {}

    public function canExposeSummary(int $userId, int $studentId): bool
    {
        // Wali siswa sendiri selalu boleh melihat ringkasan anaknya; selain itu
        // penilaian diserahkan ke pemegang izin `activity.counseling.view` yang
        // memanggil endpoint khusus (dengan audit).
        return $this->parents->canActFor($userId, $studentId);
    }

    public function developmentSignals(int $studentId): array
    {
        $leadership = ActivityMembership::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::COMPLETED->value])
            ->whereIn('role', ['CAPTAIN', 'VICE_CAPTAIN', 'SECRETARY', 'TREASURER', 'TEAM_LEADER', 'MENTOR', 'PEER_MENTOR'])
            ->with('program:id,name')
            ->get()
            ->map(fn (ActivityMembership $membership) => [
                'type' => 'LEADERSHIP',
                'program' => $membership->program?->name,
                'role' => $membership->role?->value,
            ])
            ->all();

        $achievements = ActivityAchievement::query()
            ->where('student_id', $studentId)
            ->where('verification_status', 'VERIFIED')
            ->get(['title', 'level', 'type'])
            ->map(fn (ActivityAchievement $achievement) => [
                'type' => 'ACHIEVEMENT',
                'title' => $achievement->title,
                'level' => $achievement->level?->value,
            ])
            ->all();

        return array_merge($leadership, $achievements);
    }
}
