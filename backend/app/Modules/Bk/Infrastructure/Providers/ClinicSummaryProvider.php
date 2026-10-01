<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\ClinicSupportProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Penanda kebutuhan kesehatan untuk BK — **tanpa diagnosis**.
 *
 * BK hanya perlu tahu bahwa seorang siswa baru saja beberapa kali ke UKS atau
 * masih punya tindak lanjut kesehatan; isi rekam medis tetap milik modul Clinic
 * dan hanya dibuka petugas klinik.
 */
class ClinicSummaryProvider implements ClinicSupportProviderInterface
{
    public function summary(int $studentId): ?array
    {
        if (! DB::getSchemaBuilder()->hasTable('clinic_visits')) {
            return null;
        }

        $patientId = DB::table('clinic_patients')->where('student_id', $studentId)->value('id');

        if ($patientId === null) {
            return ['visits_90d' => 0, 'follow_up_pending' => 0, 'urgent_flag' => false, 'last_visit' => null];
        }

        $visitQuery = DB::table('clinic_visits')
            ->where('patient_id', $patientId)
            ->where('visit_at', '>=', now()->subDays(90));

        $lastVisit = (clone $visitQuery)->max('visit_at');

        $followUps = DB::getSchemaBuilder()->hasTable('clinic_follow_ups')
            ? DB::table('clinic_follow_ups')->where('patient_id', $patientId)->whereIn('status', ['PENDING', 'SCHEDULED', 'OVERDUE'])->count()
            : 0;

        return [
            'visits_90d' => (clone $visitQuery)->count(),
            'follow_up_pending' => (int) $followUps,
            'urgent_flag' => (bool) (clone $visitQuery)->where('is_emergency', true)->exists(),
            'last_visit' => $lastVisit,
        ];
    }
}
