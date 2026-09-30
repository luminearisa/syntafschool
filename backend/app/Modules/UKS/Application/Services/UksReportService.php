<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\User;
use App\Modules\UKS\Domain\Enums\VisitStatus;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan operasional UKS untuk dasbor dan laporan periodik.
 *
 * Angka kunjungan, insiden, dan pemeriksaan mengikuti cakupan pengguna
 * (sekolah / kelas ampuan / anak sendiri / diri sendiri) sehingga guru dan
 * orang tua tidak pernah melihat agregat yang membocorkan identitas di luar
 * lingkup mereka.
 */
class UksReportService
{
    public function __construct(private readonly UksAccessService $access)
    {
    }

    /** @return array<string, mixed> */
    public function dashboard(User $actor, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ?? now()->startOfMonth()->toDateString();
        $toDate = $to ?? now()->toDateString();
        $ids = $this->access->visibleStudentIds($actor);

        $visits = DB::table('uks_visits')
            ->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids === [] ? [-1] : $ids))
            ->whereDate('arrived_at', '>=', $fromDate)
            ->whereDate('arrived_at', '<=', $toDate);

        $byStatus = (clone $visits)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $bySeverity = (clone $visits)
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')->pluck('total', 'severity');

        $byType = (clone $visits)
            ->select('visit_type', DB::raw('count(*) as total'))
            ->groupBy('visit_type')->pluck('total', 'visit_type');

        $topComplaints = (clone $visits)
            ->select('complaint', DB::raw('count(*) as total'))
            ->groupBy('complaint')->orderByDesc('total')->limit(5)->get();

        $openNow = DB::table('uks_visits')
            ->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids === [] ? [-1] : $ids))
            ->whereNotIn('status', [VisitStatus::PULANG->value, VisitStatus::DIRUJUK->value, VisitStatus::SELESAI->value])
            ->count();

        $incidents = DB::table('uks_incidents')
            ->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids === [] ? [-1] : $ids))
            ->whereDate('occurred_at', '>=', $fromDate)
            ->whereDate('occurred_at', '<=', $toDate)
            ->count();

        $screenings = DB::table('uks_screenings')
            ->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids === [] ? [-1] : $ids))
            ->whereDate('screened_at', '>=', $fromDate)
            ->whereDate('screened_at', '<=', $toDate);

        $screeningByResult = (clone $screenings)
            ->select('result', DB::raw('count(*) as total'))
            ->groupBy('result')->pluck('total', 'result');

        return [
            'window' => ['from' => $fromDate, 'to' => $toDate],
            'scope' => $this->scopePayload($actor, $ids),
            'visits' => [
                'total' => (clone $visits)->count(),
                'open_now' => $openNow,
                'sent_home' => (clone $visits)->where('sent_home', true)->count(),
                'referred' => (clone $visits)->where('status', VisitStatus::DIRUJUK->value)->count(),
                'parent_notified' => (clone $visits)->where('parent_notified', true)->count(),
                'by_status' => $byStatus,
                'by_severity' => $bySeverity,
                'by_type' => $byType,
                'top_complaints' => $topComplaints,
            ],
            'incidents' => ['total' => $incidents],
            'screenings' => [
                'total' => (clone $screenings)->count(),
                'by_result' => $screeningByResult,
            ],
            'stock' => $this->canSeeStock($actor) ? $this->stockSummary() : null,
        ];
    }

    /** Laporan harian kunjungan (untuk ekspor). @return list<array<string, mixed>> */
    public function dailyRows(User $actor, string $date): array
    {
        $ids = $this->access->visibleStudentIds($actor);

        return DB::table('uks_visits as v')
            ->join('students as s', 's.id', '=', 'v.student_id')
            ->leftJoin('school_classes as c', 'c.id', '=', 'v.school_class_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('v.student_id', $ids === [] ? [-1] : $ids))
            ->whereDate('v.arrived_at', $date)
            ->orderBy('v.arrived_at')
            ->get(['v.code', 's.full_name', 's.nis', 'c.name as class_name', 'v.visit_type', 'v.complaint', 'v.severity', 'v.status', 'v.arrived_at', 'v.sent_home'])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /** @return array<string, mixed> */
    private function stockSummary(): array
    {
        $days = (int) config('uks.expiry_warning_days', 30);

        return [
            'medicines' => DB::table('uks_medicines')->where('is_active', true)->count(),
            'low_stock' => DB::table('uks_medicines')->where('is_active', true)->whereColumn('stock', '<=', 'min_stock')->count(),
            'expiring_soon' => DB::table('uks_medicines')->where('is_active', true)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
                ->count(),
            'expired' => DB::table('uks_medicines')->where('is_active', true)
                ->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString())->count(),
        ];
    }

    private function canSeeStock(User $actor): bool
    {
        return $this->access->canOperate($actor) || $actor->hasPermissionTo('uks.medicine.view');
    }

    /** @param list<int>|null $ids */
    private function scopePayload(User $actor, ?array $ids): array
    {
        if ($ids === null) {
            return ['level' => 'SEKOLAH', 'label' => 'Seluruh sekolah'];
        }

        $context = $this->access->context($actor);

        return [
            'level' => $context->isStudent() ? 'DIRI' : ($context->isGuardian() ? 'ANAK' : 'KELAS'),
            'label' => $context->isStudent()
                ? 'Data diri sendiri'
                : ($context->isGuardian() ? 'Data anak sendiri' : 'Siswa di kelas yang diampu'),
            'student_count' => count($ids),
        ];
    }
}
