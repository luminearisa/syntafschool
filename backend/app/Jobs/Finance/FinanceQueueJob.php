<?php

namespace App\Jobs\Finance;

use App\Models\FinanceOperation;
use App\Models\User;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dasar pekerjaan Keuangan yang di-queue (§28).
 *
 * Tugas kelas ini hanya menjaga satu hal: sebuah pekerjaan yang masuk antrian
 * tidak pernah berakhir tanpa status. Queued → Processing → Completed/Failed
 * ditulis di sini, sehingga subclass tidak bisa lupa melapor dan meninggalkan
 * baris membeku di "Processing" — bentuk kegagalan observability yang paling
 * menyesatkan, karena terlihat seperti pekerjaan yang sedang jalan.
 *
 * `handle()` melempar kembali kegagalan setelah mencatatnya. Tanpa itu worker
 * menganggap job selesai dengan baik dan `failed_jobs` kehilangan jejak aslinya;
 * dengan itu, register ini tetap bisa dibaca bendahara sementara log server
 * dipakai teknisi.
 *
 * `tries = 1` bukan ketiadaan percobaan ulang: yang diulang adalah *pekerjaan*,
 * dan itu terjadi di register (§29, `retry()`) setelah seseorang memutuskan.
 * Retry otomatis di level worker justru berbahaya untuk jalur yang menyentuh
 * dokumen — keputusan "ulangi atau tidak" adalah keputusan akuntansi.
 */
abstract class FinanceQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  argumen pekerjaan, tersimpan di register untuk retry
     */
    final public function __construct(public int $operationId, public array $payload = []) {}

    public function handle(FinanceOperationService $operations): void
    {
        $operation = FinanceOperation::query()->find($this->operationId);

        if ($operation === null) {
            return;
        }

        // Pengiriman ganda ("at least once") tidak mengulang pekerjaan yang sudah
        // selesai. Ini penjaga lapis kedua; yang pertama tetap idempotensi service.
        if ($operation->status === FinanceOperation::STATUS_COMPLETED) {
            return;
        }

        $operations->start($operation);

        try {
            $operations->complete($operation, $this->process($operation, $operations));
        } catch (\Throwable $e) {
            $operations->fail($operation, $e, $this->retryable(), $this->failureContext($e));

            throw $e;
        }
    }

    /** Sisa dari worker (job dibuang/kehabisan masa): status wajib berhenti di Failed. */
    public function failed(\Throwable $exception): void
    {
        $operation = FinanceOperation::query()->find($this->operationId);

        if ($operation !== null && $operation->isRunning()) {
            app(FinanceOperationService::class)->fail($operation, $exception, $this->retryable());
        }
    }

    /** Nama operasi di register; dipakai juga untuk memetakan retry. */
    abstract public static function operationName(): string;

    /**
     * Isi pekerjaan. Mengembalikan ringkasan untuk disimpan di `result`.
     *
     * @return array<string, mixed>
     */
    abstract protected function process(FinanceOperation $operation, FinanceOperationService $operations): array;

    /**
     * Semua pekerjaan di register ini memanggil service yang menolak duplikat,
     * jadi mengulangnya aman. Subclass yang suatu hari menyentuh jalur tanpa
     * jaminan itu wajib mengembalikan false di sini.
     */
    protected function retryable(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function failureContext(\Throwable $error): array
    {
        return ['job' => static::class];
    }

    /** Pemanggil atas nama siapa pekerjaan jalan (audit trail tetap menunjuk orang). */
    protected function actor(): ?User
    {
        $actorId = $this->payload['actor_id'] ?? null;

        return $actorId !== null ? User::query()->find((int) $actorId) : null;
    }
}
