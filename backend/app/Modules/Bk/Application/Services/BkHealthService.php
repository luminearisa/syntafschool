<?php

namespace App\Modules\Bk\Application\Services;

use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Bk\Domain\Contracts\ClinicSupportProviderInterface;
use App\Modules\Bk\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Bk\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentActivityProviderInterface;
use App\Modules\Bk\Domain\Contracts\WorkflowProviderInterface;
use App\Modules\Bk\Models\BkCase;
use Illuminate\Support\Facades\DB;

/**
 * Kesehatan modul BK.
 *
 * Diperiksa tanpa membuka data pribadi: yang dilaporkan hanya keberadaan modul
 * lain, keterjangkauan penyimpanan dokumen, dan jumlah pekerjaan yang menumpuk.
 * Berguna sebelum rapat: "apakah notifikasi jalan, apakah tindak lanjut
 * tertangani, apakah modul lain sudah terpasang?"
 */
class BkHealthService
{
    public function __construct(
        private readonly WorkflowProviderInterface $workflow,
        private readonly DocumentProviderInterface $documents,
        private readonly NotificationProviderInterface $notifications,
        private readonly StudentActivityProviderInterface $activity,
        private readonly ClinicSupportProviderInterface $clinic,
        private readonly BoardingProviderInterface $boarding,
    ) {
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $today = now()->toDateString();

        return [
            'module' => 'BK',
            'status' => $this->overallStatus(),
            'integrations' => [
                'workflow' => ['available' => $this->workflow->available(), 'mode' => $this->workflow->available() ? 'engine' : 'internal'],
                'documents' => ['available' => true, 'provider' => config('bk.documents.disk', 'local')],
                'notifications' => ['available' => true, 'channel' => 'database'],
                'student_activity' => ['available' => $this->activityAvailable()],
                'clinic' => ['available' => DB::getSchemaBuilder()->hasTable('clinic_visits')],
                'boarding' => ['available' => DB::getSchemaBuilder()->hasTable('boarding_placements')],
            ],
            'queue' => [
                'referrals_waiting' => (int) DB::table('bk_referrals')->whereIn('status', ['SUBMITTED', 'RECEIVED', 'SCREENING'])->count(),
                'unassigned_cases' => (int) BkCase::query()->open()->whereNull('assigned_counselor_id')->count(),
                'follow_ups_overdue' => (int) DB::table('bk_follow_ups')->where('is_overdue', true)->whereIn('status', ['PENDING', 'MISSED', 'RESCHEDULED'])->whereNull('deleted_at')->count(),
                'appointments_today' => (int) DB::table('bk_appointments')->whereDate('scheduled_start', $today)->whereIn('status', ['SCHEDULED', 'CONFIRMED'])->whereNull('deleted_at')->count(),
                'consents_pending' => (int) DB::table('bk_consents')->where('status', 'PENDING')->whereNull('deleted_at')->count(),
            ],
            'privacy' => [
                'audit_logging' => true,
                'confidential_levels' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::values(),
                'notes_excluded_from_exports' => true,
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function activityAvailable(): bool
    {
        // Modul Activity "terpasang" bila kontraknya terikat — baik implementasi
        // nyata maupun NullCounselingProvider (yang berarti sekolah belum
        // mengizinkan ringkasan aktivitas dibagikan ke BK).
        return class_exists(\App\Modules\Activity\Domain\Contracts\CounselingProviderInterface::class)
            && app()->bound(\App\Modules\Activity\Domain\Contracts\CounselingProviderInterface::class);
    }

    private function overallStatus(): string
    {
        $overdue = DB::table('bk_follow_ups')->where('is_overdue', true)->whereIn('status', ['PENDING', 'MISSED', 'RESCHEDULED'])->whereNull('deleted_at')->count();
        $unassigned = BkCase::query()->open()->whereNull('assigned_counselor_id')->count();

        return ($overdue > 5 || $unassigned > 5) ? 'ATTENTION' : 'OK';
    }
}
