<?php

namespace App\Modules\Clinic\Console;

use App\Modules\Clinic\Application\Services\ClinicNotificationService;
use App\Modules\Clinic\Models\ClinicFollowUp;
use Illuminate\Console\Command;

/**
 * Tandai tindak lanjut yang lewat tenggat dan ingatkan orang tua.
 *
 * Dijalankan harian oleh penjadwal. Idempotent: tindak lanjut yang sudah
 * berstatus OVERDUE tidak diberitakan ulang karena kunci dedupe-nya sama.
 */
class ClinicExpireFollowUpsCommand extends Command
{
    protected $signature = 'clinic:expire-follow-ups {--limit=200}';

    protected $description = 'Tandai tindak lanjut klinik yang terlambat dan kirim pengingat ke orang tua';

    public function handle(ClinicNotificationService $notifications): int
    {
        $limit = (int) $this->option('limit');

        $rows = ClinicFollowUp::query()
            ->with('patient.student:id,full_name')
            ->whereIn('status', ['PENDING', 'SCHEDULED'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada tindak lanjut yang terlambat.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($rows as $followUp) {
            $followUp->status = 'OVERDUE';
            $followUp->save();

            if ($followUp->patient !== null) {
                $result = $notifications->notifyFollowUp(
                    $followUp->patient,
                    $followUp->due_date?->toDateString() ?? now()->toDateString(),
                    $followUp->type,
                    \App\Models\User::query()->first() ?? new \App\Models\User,
                );

                $notified += (int) ($result['sent'] ?? 0);
            }
        }

        $this->info("Tindak lanjut ditandai terlambat: {$rows->count()}; pemberitahuan terkirim: {$notified}.");

        return self::SUCCESS;
    }
}
