<?php

namespace App\Listeners;

use App\Models\FinanceOperation;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Console\Events\ScheduledTaskFailed;

/**
 * Scheduler yang jatuh tidak boleh hanya menjadi baris di log server (§29).
 *
 * Tugas harian Keuangan (tangga pengingat, `overdue`, `expired`, kanal bayar
 * kedaluwarsa, generate SPP) adalah satu-satunya cara status-status itu bergerak.
 * Kalau perintahnya mati karena satu data rusak, seluruh menagihan berhenti tanpa
 * ada yang tahu — dan tidak ada "layar Keuangan" yang bisa dipakai untuk
 * memperbaikinya. Listener ini menaruh kegagalannya di register operasi, tempat
 * bendahara membacanya, lengkap dengan perintah mana dan kenapa.
 *
 * Pintu masuknya satu event bawaan Laravel, bukan pembungkus perintah: begitu
 * perintah dibungkus sendiri, perintah yang tidak dibungkus jadi jalan pintas di
 * luar pencatatan.
 */
class RecordScheduledTaskFailure
{
    public function __construct(private readonly FinanceOperationService $operations) {}

    public function handle(ScheduledTaskFailed $event): void
    {
        $command = (string) ($event->task->command ?? '');
        $description = (string) ($event->task->description ?? '');

        // Dua bentuk tugas dijadwalkan ikut dicatat: perintah `finance:...` dan
        // closure yang *dinamai* `finance:...` — jalur antrian di `routes/console.php`
        // memakai bentuk kedua, dan tanpa nama itu ia tidak akan pernah dikenali.
        if (! str_contains($command, 'finance:') && ! str_contains($description, 'finance:')) {
            return;
        }

        $this->operations->recordIncident(
            FinanceOperation::EVENT_SCHEDULED_JOB,
            $event->exception,
            [
                'command' => $command !== '' ? $command : $description,
                'description' => $description,
                'expression' => $event->task->expression,
            ],
            // Perintah scheduler Keuangan idempoten (dokumen yang sama tidak
            // dinotifikasi/diterbitkan dua kali), jadi menjalankan ulang tugas yang
            // gagal selalu aman setelah penyebabnya diperbaiki.
            retryable: true,
        );
    }
}
