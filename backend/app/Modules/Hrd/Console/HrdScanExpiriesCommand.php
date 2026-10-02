<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Application\Services\CompetencyService;
use App\Modules\Hrd\Application\Services\DocumentService;
use App\Modules\Hrd\Application\Services\EmploymentService;
use App\Modules\Hrd\Application\Services\HrdNotificationService;
use App\Modules\Hrd\Application\Services\LifecycleProcessService;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeDocument;
use App\Modules\Hrd\Models\HrOffboardingTask;
use App\Modules\Hrd\Models\HrOnboardingTask;
use App\Modules\Hrd\Models\HrProbation;
use App\Modules\Hrd\Models\HrRequest;
use Illuminate\Console\Command;

/**
 * Penjadwal harian: apa yang harus diketahui HR hari ini (§70).
 *
 * Satu perintah, satu alasannya: seluruh tenggat kepegawaian — kontrak, masa
 * percobaan, dokumen, sertifikasi, tenggat onboarding/offboarding, dan SLA
 * permintaan HR — dihitung ulang lalu dilaporkan sekali sehari. Status dihitung
 * ulang dari tanggalnya, bukan disimpan manual, sehingga tidak ada "kontrak
 * kedaluwarsa yang masih terbaca aktif" hanya karena tak ada yang memperbarui.
 */
class HrdScanExpiriesCommand extends Command
{
    protected $signature = 'hrd:scan-expiries {--days=90 : Jendela pemindaian dalam hari} {--dry-run : Hanya laporkan, tanpa mengirim notifikasi}';

    protected $description = 'Pindai kontrak, dokumen, sertifikasi, masa percobaan, tenggat tugas, dan SLA permintaan HR.';

    public function handle(
        EmploymentService $employment,
        DocumentService $documents,
        CompetencyService $competency,
        LifecycleProcessService $processes,
        HrdNotificationService $notifications,
        HrdSettingsService $settings,
    ): int {
        $window = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        $contracts = $employment->refreshContractStatuses();
        $documentsRecalculated = 0;

        HrEmployeeDocument::query()
            ->whereNotNull('expiry_date')
            ->whereNotIn('status', ['revoked', 'replaced', 'archived'])
            ->chunkById(200, function ($rows) use ($documents, &$documentsRecalculated): void {
                foreach ($rows as $document) {
                    $status = $documents->recalculateStatus($document);

                    if ($status !== $document->status) {
                        $document->forceFill(['status' => $status])->save();
                        $documentsRecalculated++;
                    }
                }
            });

        $certifications = $competency->refreshCertificationStatuses($settings->integer('expiry_warning_days', 60));

        if (! $dryRun) {
            $this->sendContractReminders($notifications, $settings, $window);
            $competency->sendExpiryReminders($settings->integer('expiry_warning_days', 60));
            $this->sendDocumentReminders($notifications, $window);
            $this->sendProbationReminders($notifications, $settings, $window);
            $this->sendTaskReminders($notifications, $processes, $settings);
            $this->flagBreachingRequests($notifications);
        }

        $this->info(sprintf(
            'Kontrak: %d akan berakhir, %d kedaluwarsa. Dokumen: %d status diperbarui. Sertifikasi: %d kedaluwarsa, %d akan berakhir.',
            $contracts['expiring'],
            $contracts['expired'],
            $documentsRecalculated,
            $certifications['expired'],
            $certifications['expiring'],
        ));

        return self::SUCCESS;
    }

    private function sendContractReminders(HrdNotificationService $notifications, HrdSettingsService $settings, int $window): void
    {
        foreach ($settings->reminderWindows('contract_days') as $days) {
            if ($days > $window) {
                continue;
            }

            $contracts = HrContract::query()
                ->with(['employee:id,user_id,employee_number,person_id', 'employee.person:id,full_name'])
                ->whereIn('status', ['active', 'expiring'])
                ->whereDate('end_date', now()->addDays($days)->toDateString())
                ->get();

            foreach ($contracts as $contract) {
                if ($contract->employee === null) {
                    continue;
                }

                $notifications->notify(
                    'contract.expiring',
                    "Kontrak berakhir dalam {$days} hari",
                    'Kontrak '.$contract->contract_number.' berakhir pada '.$contract->end_date?->format('d/m/Y').'.',
                    array_values(array_filter([(int) ($contract->employee->user_id ?? 0)])),
                    (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
                    $contract->employee,
                    ['contract_id' => (int) $contract->getKey(), 'days' => $days],
                );

                $contract->forceFill(['reminder_sent_at' => now()])->save();
            }
        }
    }

    private function sendDocumentReminders(HrdNotificationService $notifications, int $window): void
    {
        $rows = HrEmployeeDocument::query()
            ->with(['employee:id,user_id,person_id', 'documentType:id,name'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(min($window, 30))->toDateString())
            ->whereNotIn('status', ['revoked', 'replaced', 'archived'])
            ->get();

        foreach ($rows as $document) {
            if ($document->employee === null) {
                continue;
            }

            $notifications->notify(
                'document.expiring',
                'Dokumen akan berakhir',
                ($document->documentType?->name ?? 'Dokumen').' berakhir pada '.$document->expiry_date?->format('d/m/Y').'.',
                array_values(array_filter([(int) ($document->employee->user_id ?? 0)])),
                (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
                $document->employee,
                ['document_id' => (int) $document->getKey()],
            );

            $document->forceFill(['last_reminder_at' => now()])->save();
        }
    }

    private function sendProbationReminders(HrdNotificationService $notifications, HrdSettingsService $settings, int $window): void
    {
        foreach ($settings->reminderWindows('probation_days') as $days) {
            if ($days > $window) {
                continue;
            }

            $probations = HrProbation::query()
                ->with(['employee:id,user_id,person_id', 'employee.person:id,full_name'])
                ->whereIn('status', ['pending', 'active', 'extended'])
                ->whereDate('end_date', now()->addDays($days)->toDateString())
                ->get();

            foreach ($probations as $probation) {
                if ($probation->employee === null) {
                    continue;
                }

                $notifications->notify(
                    'probation.ending',
                    "Masa percobaan berakhir dalam {$days} hari",
                    $probation->employee->displayName().' — jadwalkan penilaian sebelum '.$probation->end_date?->format('d/m/Y').'.',
                    [],
                    (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
                    $probation->employee,
                    ['probation_id' => (int) $probation->getKey()],
                );

                $probation->forceFill(['reminder_sent_at' => now()])->save();
            }
        }
    }

    private function sendTaskReminders(HrdNotificationService $notifications, LifecycleProcessService $processes, HrdSettingsService $settings): void
    {
        $windows = $settings->reminderWindows('onboarding_due_days');

        foreach ($windows as $days) {
            HrOnboardingTask::query()
                ->with(['process.employee:id,user_id,person_id', 'process.employee.person:id,full_name'])
                ->open()
                ->whereDate('due_date', now()->addDays($days)->toDateString())
                ->get()
                ->each(function (HrOnboardingTask $task) use ($notifications, $days): void {
                    $employee = $task->process?->employee;

                    if ($employee === null) {
                        return;
                    }

                    $notifications->notify(
                        'onboarding.task_due',
                        "Tenggat tugas onboarding dalam {$days} hari",
                        $task->title.' — '.$employee->displayName().'.',
                        array_values(array_filter([(int) ($task->owner_user_id ?? 0), (int) ($employee->user_id ?? 0)])),
                        [],
                        $employee,
                        ['task_id' => (int) $task->getKey()],
                    );

                    $task->forceFill(['reminder_sent_at' => now()])->save();
                });

            HrOnboardingTask::query()
                ->open()
                ->whereDate('due_date', '<', now()->toDateString())
                ->update(['status' => HrOnboardingTask::STATUS_OVERDUE]);

            HrOffboardingTask::query()
                ->open()
                ->whereDate('due_date', '<', now()->toDateString())
                ->update(['status' => 'overdue']);
        }

        unset($processes);
    }

    /** Permintaan HR yang melewati SLA ditandai dan dilaporkan (§70). */
    private function flagBreachingRequests(HrdNotificationService $notifications): void
    {
        HrRequest::query()
            ->breachingSla()
            ->whereNull('sla_breached_at')
            ->update(['sla_breached_at' => now()]);

        $count = HrRequest::query()->breachingSla()->count();

        if ($count > 0) {
            $notifications->notifyHr(
                'request.sla_breach',
                'Permintaan HR melewati SLA',
                $count.' permintaan melewati batas waktu penyelesaian.',
                ['count' => $count],
            );
        }

        unset($notifications);
    }
}
