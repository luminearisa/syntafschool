<?php

namespace App\Modules\LMS\Jobs;

use App\Modules\LMS\Application\Services\BadgeService;
use App\Modules\LMS\Application\Services\CertificateService;
use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Domain\Support\LmsSystemActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Otomatisasi harian LMS (§11).
 *
 * Satu job yang merangkum pekerjaan berkala — sertifikat otomatis dan evaluasi
 * badge — supaya penjadwalan tidak menjadi daftar panjang yang mudah terlupa.
 * Semua aksi memakai service Phase 4 sehingga idempotensinya sudah dijamin di
 * sana (satu sertifikat per pendaftaran, satu badge per siswa/course).
 */
class RunLmsAutomationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly bool $issueCertificates = true, public readonly bool $evaluateBadges = true) {}

    public function uniqueId(): string
    {
        return 'lms-automation';
    }

    public function handle(CertificateService $certificates, BadgeService $badges, LmsGateway $gateway): void
    {
        $actor = LmsSystemActor::make();
        $summary = ['certificates' => 0, 'badges' => 0, 'courses' => 0];

        $courses = LmsCourse::query()
            ->whereIn('status', ['PUBLISHED', 'ARCHIVED'])
            ->get();

        foreach ($courses as $course) {
            $summary['courses']++;

            if ($this->issueCertificates && (bool) config('lms.automation.certificate_auto_issue', true) && (bool) $course->certificate_enabled) {
                $completed = LmsEnrollment::query()
                    ->where('course_id', $course->id)
                    ->where('role', 'STUDENT')
                    ->where('status', 'COMPLETED')
                    ->get();

                foreach ($completed as $enrollment) {
                    if ($certificates->issueIfEligible($enrollment, $actor) !== null) {
                        $summary['certificates']++;
                    }
                }
            }

            if ($this->evaluateBadges && (bool) config('lms.automation.badge_evaluation', true)) {
                $result = $badges->evaluateCourse($course, $actor);
                $summary['badges'] += (int) ($result['awarded'] ?? 0);
            }
        }

        cache()->put('lms:automation:last_run', now()->toIso8601String(), now()->addDay());
        cache()->put('lms:automation:summary', $summary, now()->addDay());

        // Penjadwal dianggap hidup bila job ini berjalan.
        cache()->put('lms:scheduler:last_run', now()->toIso8601String(), now()->addDays(2));
    }
}
