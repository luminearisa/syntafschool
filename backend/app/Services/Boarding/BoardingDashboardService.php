<?php

namespace App\Services\Boarding;

use App\Models\BoardingAttendance;
use App\Models\BoardingBed;
use App\Models\BoardingHomecoming;
use App\Models\BoardingPermission;
use App\Models\BoardingVisitor;
use App\Models\User;

/**
 * Angka ringkas dashboard asrama (§43) — satu permintaan, satu muatan.
 *
 * Semua angka mengikuti `BoardingScopeService`: wali kamar melihat angka untuk
 * binaannya sendiri, petugas/kepala asrama melihat seluruh asrama. Absensi
 * sengaja dibaca lewat `BoardingAttendanceService::query()` (bukan query mentah)
 * supaya lingkupnya tidak punya dua sumber kebenaran.
 */
class BoardingDashboardService
{
    public function __construct(
        protected readonly BoardingScopeService $scope,
        protected readonly BoardingStatusService $statuses,
        protected readonly BoardingViolationService $violations,
        protected readonly BoardingAttendanceService $attendances,
        protected readonly BoardingAchievementService $achievements,
        protected readonly BoardingHealthService $health,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(?User $actor = null): array
    {
        $allowed = $this->scope->studentIdsFor($actor);

        $placedIds = $this->placedStudentIds($allowed);

        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();

        $attendanceToday = $this->attendances->query(['date' => $today], $actor)
            ->reorder()
            ->selectRaw('COALESCE(NULLIF(boarding_attendance_types.name, \'\'), \'Lainnya\') as type_name, COUNT(*) as aggregate')
            ->leftJoin('boarding_attendance_types', 'boarding_attendance_types.id', '=', 'boarding_attendances.attendance_type_id')
            ->groupBy('type_name')
            ->pluck('aggregate', 'type_name')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();

        $lateToday = (int) $this->attendances->query(['date' => $today], $actor)->where('is_late', true)->count();

        $violationMonthQuery = $this->violations->query(['from' => $monthStart, 'to' => $today], $actor);

        return [
            'generated_at' => now()->toIso8601String(),
            'point_label' => $this->violations->pointLabel(),
            'students' => [
                'in_boarding' => count($placedIds),
                'status_counts' => $this->statuses->countsFor($placedIds),
                'out_now' => $this->outNowCount($placedIds),
            ],
            'beds' => [
                'total' => (int) BoardingBed::query()->count(),
                'occupied' => (int) BoardingBed::query()->where('status', BoardingBed::STATUS_OCCUPIED)->count(),
                'available' => (int) BoardingBed::query()->where('status', BoardingBed::STATUS_AVAILABLE)->count(),
                'maintenance' => (int) BoardingBed::query()->where('status', BoardingBed::STATUS_MAINTENANCE)->count(),
            ],
            'attendance' => [
                'date' => $today,
                'by_type' => $attendanceToday,
                'total' => array_sum($attendanceToday),
                'late' => $lateToday,
            ],
            'discipline' => [
                'violations_month' => (clone $violationMonthQuery)->count(),
                'points_month' => (int) (clone $violationMonthQuery)->sum('points'),
                'violations_open' => $this->violations->query(['status' => 'open'], $actor)->count(),
                'actions_pending' => $this->violations->actionQuery(['status' => 'pending'], $actor)->count(),
            ],
            'permissions' => [
                'awaiting_approval' => $this->awaitingApprovalCount(),
                'out' => $this->outNowCount($placedIds),
                'late_returns_month' => BoardingPermission::query()
                    ->where('late_return', true)
                    ->whereDate('actual_return_at', '>=', $monthStart)
                    ->count(),
            ],
            'homecomings' => [
                'awaiting_approval' => $this->awaitingHomecomingCount(),
            ],
            'visitors' => [
                'present' => BoardingVisitor::query()
                    ->whereIn('status', [BoardingVisitor::STATUS_WAITING, BoardingVisitor::STATUS_VISITING])
                    ->whereDate('visit_date', $today)
                    ->count(),
            ],
            'health' => [
                'open_records' => $this->health->query(['open_only' => 1], $actor)->count(),
                'in_isolation' => $this->health->activeIsolations($actor)->count(),
            ],
            'achievements' => [
                'this_month' => $this->achievements->query(['from' => $monthStart], $actor)->count(),
            ],
        ];
    }

    /**
     * Id santri dengan penempatan aktif, mengikuti lingkup hak akses.
     *
     * @param  list<int>|null  $allowed  null = seluruh santri
     * @return list<int>
     */
    protected function placedStudentIds(?array $allowed): array
    {
        return \App\Models\BoardingPlacement::query()
            ->active()
            ->when($allowed !== null, fn ($q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->pluck('student_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Santri yang sedang di luar (izin keluar / kepulangan) — dari status service
     * supaya definisinya satu dengan kartu santri (§42).
     *
     * @param  list<int>  $ids
     */
    protected function outNowCount(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $counts = $this->statuses->countsFor($ids);

        return (int) $counts[BoardingStatusService::OUT_WITH_PERMISSION]
            + (int) $counts[BoardingStatusService::HOME];
    }

    protected function awaitingApprovalCount(): int
    {
        // Steps approval dibaca dari settings di dalam service perizinan; angka
        // dashboard cukup "masih menunggu", status submitted apa adanya.
        return (int) BoardingPermission::query()
            ->where('status', BoardingPermission::STATUS_SUBMITTED)
            ->count();
    }

    protected function awaitingHomecomingCount(): int
    {
        return (int) BoardingHomecoming::query()
            ->where('status', BoardingHomecoming::STATUS_SUBMITTED)
            ->count();
    }
}
