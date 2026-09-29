<?php

namespace App\Modules\LMS\Console\Commands;

use App\Modules\LMS\Application\Services\BadgeService;
use App\Modules\LMS\Application\Services\CertificateService;
use App\Modules\LMS\Application\Services\LearningHealthService;
use App\Modules\LMS\Application\Services\LmsGateway;
use App\Modules\LMS\Jobs\RefreshLearningHealthJob;
use App\Modules\LMS\Jobs\RunLmsAutomationJob;
use Illuminate\Console\Command;

/**
 * `php artisan lms:automation` — pekerjaan berkala LMS (§11).
 *
 * Menjalankan penyegaran kesehatan belajar, penerbitan sertifikat otomatis, dan
 * evaluasi badge. Aman dijalankan ulang (semua langkah idempotent).
 */
class LmsAutomationCommand extends Command
{
    protected $signature = 'lms:automation
        {--now : Jalankan langsung, bukan lewat antrean}
        {--skip-health : Lewati penyegaran kesehatan belajar}
        {--skip-certificates : Lewati penerbitan sertifikat otomatis}
        {--skip-badges : Lewati evaluasi badge}';

    protected $description = 'Menjalankan otomatisasi LMS (kesehatan belajar, sertifikat, badge)';

    public function handle(LearningHealthService $health): int
    {
        $direct = (bool) $this->option('now');

        if (! $this->option('skip-health')) {
            if ($direct) {
                $this->info('Menyegarkan kesehatan belajar: '.$health->refreshActiveCourses().' pendaftaran diperbarui.');
            } else {
                RefreshLearningHealthJob::dispatch();
                $this->info('Penyegaran kesehatan belajar dimasukkan ke antrean.');
            }
        }

        $job = new RunLmsAutomationJob(
            issueCertificates: ! $this->option('skip-certificates'),
            evaluateBadges: ! $this->option('skip-badges'),
        );

        if ($direct) {
            $job->handle(
                app(CertificateService::class),
                app(BadgeService::class),
                app(LmsGateway::class),
            );

            $summary = (array) cache()->get('lms:automation:summary', []);
            $this->info('Otomatisasi selesai: '.json_encode($summary));
        } else {
            $job::dispatch();
            $this->info('Otomatisasi dimasukkan ke antrean.');
        }

        // Penanda penjadwal hidup (dipakai health check §42).
        cache()->put('lms:scheduler:last_run', now()->toIso8601String(), now()->addDays(2));

        return self::SUCCESS;
    }
}
