<?php

namespace App\Modules\Bk\Jobs;

use App\Modules\Bk\Application\Services\BkAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pekerjaan antrean untuk tindakan otomasi.
 *
 * Dipakai agar pengiriman notifikasi dan pekerjaan berat lain tidak menahan
 * permintaan pengguna maupun perintah terjadwal. Pekerjaan ini aman diulang:
 * layanan otomasi memeriksa kunci idempotensinya lebih dulu.
 */
class BkAutomationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $actionCode,
        public readonly int $subjectId,
        public readonly array $payload = [],
    ) {
        $this->onQueue((string) config('bk.queue.default', 'bk'));
    }

    public function handle(BkAutomationService $automation): void
    {
        match ($this->actionCode) {
            'APPOINTMENT_REMINDER' => $automation->run('APPOINTMENT_REMINDER_QUEUED', 'appointment', $this->subjectId, fn (): array => [
                'recipients' => 1,
                'reason' => 'Pengingat dikirim melalui antrean.',
                'payload' => $this->payload,
            ], period: null),
            default => null,
        };
    }
}
