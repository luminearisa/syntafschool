<?php

namespace App\Services\Finance;

use App\Jobs\Finance\ExpirePaymentsJob;
use App\Jobs\Finance\GenerateBillingsJob;
use App\Jobs\Finance\GenerateScheduledSppJob;
use App\Jobs\Finance\IssueInvoicesJob;
use App\Jobs\Finance\RunDailyMaintenanceJob;
use App\Jobs\Finance\SendInvoiceRemindersJob;
use App\Models\FinanceOperation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Register operasi Keuangan (§28, §29).
 *
 * Dua hal yang disediakan di satu tempat: kemajuan pekerjaan berat yang
 * di-queue (Queued → Processing → Completed/Failed, §28) dan jejak kegagalan
 * operasional yang terjadi di jalur nyata (§29: "apa yang gagal, kapan, dokumen
 * mana, kenapa, bisa diulang?").
 *
 * Kenapa perlu register sendiri, bukan hanya log aplikasi? `storage/logs` menjawab
 * "ada error" tetapi tidak menjawab "tagihan siapa yang belum terbit, dan apakah
 * menekan tombol ulang aman". Baris di sinilah yang bisa dibaca bendahara tanpa
 * membuka server, dan `payload`-nya yang dipakai untuk menjalankan ulang.
 *
 * Prinsip yang dijaga:
 * - **Tidak ada kelas job yang berasal dari input klien.** Nama operasi dipetakan
 *   lewat `JOB_CLASSES`; nama di luar daftar ditolak. Tanpa itu, endpoint retry
 *   menjadi mesin pembuat objek dari string mana pun.
 * - **Pencatatan tidak boleh menggagalkan pekerjaan.** `recordIncident()` menelan
 *   kesalahannya sendiri dan selalu menyisakan `Log::error` sebagai jaring
 *   terakhir; kalau ia dipanggil dari dalam transaksi yang kemudian di-rollback,
 *   barisnya ikut hilang bersama aksi yang gagal itu — bukan sebaliknya.
 * - **Retry hanya dijanjikan untuk jalur yang idempoten.** Service di baliknya
 *   (billing per santri/paket/periode, invoice per billing, notifikasi per
 *   referensi) menolak duplikat, sehingga menjalankan ulang pekerjaan tidak pernah
 *   melahirkan transaksi kedua (§29).
 */
class FinanceOperationService
{
    /** Nama operasi → kelas job. Whitelist sekaligus peta retry. */
    public const JOB_CLASSES = [
        FinanceOperation::JOB_SPP_GENERATE => GenerateScheduledSppJob::class,
        FinanceOperation::JOB_BILLING_GENERATE => GenerateBillingsJob::class,
        FinanceOperation::JOB_INVOICE_ISSUE => IssueInvoicesJob::class,
        FinanceOperation::JOB_REMINDERS_SEND => SendInvoiceRemindersJob::class,
        FinanceOperation::JOB_PAYMENTS_EXPIRE => ExpirePaymentsJob::class,
        FinanceOperation::JOB_DAILY_MAINTENANCE => RunDailyMaintenanceJob::class,
    ];

    /**
     * Catat sebuah job yang belum jalan (Queued).
     *
     * @param  int|null  $total  jumlah baris yang akan diproses, bila sudah diketahui
     */
    public function queue(string $name, array $payload = [], ?User $actor = null, ?int $total = null): FinanceOperation
    {
        return FinanceOperation::create([
            'name' => $name,
            'kind' => FinanceOperation::KIND_JOB,
            'status' => FinanceOperation::STATUS_QUEUED,
            'payload' => $payload,
            'total_rows' => $total,
            'queued_at' => Carbon::now(),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Buat job Queued lalu lempar ke antrian. Satu-satunya jalur yang disarankan
     * untuk memulai pekerjaan berat dari HTTP.
     *
     * `actor_id` ikut tersimpan di payload, bukan hanya di kolom `created_by`:
     * dokumen yang lahir di dalam job harus tetap atas nama orang yang menekan
     * tombol, termasuk ketika pekerjaan itu dijalankan ulang minggu depan oleh
     * orang lain.
     */
    public function dispatch(string $name, array $payload = [], ?User $actor = null, ?int $total = null): FinanceOperation
    {
        $class = self::JOB_CLASSES[$name] ?? null;

        if ($class === null) {
            throw ValidationException::withMessages([
                'job' => 'Jenis pekerjaan tidak dikenal atau tidak dapat diantrekan.',
            ]);
        }

        // `actor_id` selalu milik pemanggil, bukan milik payload: kalau tidak,
        // pengguna bisa menyuruh job menerbitkan dokumen atas nama orang lain.
        $payload = array_merge($payload, ['actor_id' => $actor?->id]);

        $operation = $this->queue($name, $payload, $actor, $total);

        $class::dispatch((int) $operation->id, $operation->payload)->onQueue('finance');

        // Pada driver antrian `sync` pekerjaannya sudah selesai di baris di atas,
        // jadi status dimuat ulang: yang dikembalikan bukan "Mengantre" yang basi.
        return $operation->fresh() ?? $operation;
    }

    public function start(FinanceOperation $operation): void
    {
        $operation->update([
            'status' => FinanceOperation::STATUS_PROCESSING,
            'attempts' => (int) $operation->attempts + 1,
            'started_at' => Carbon::now(),
            'finished_at' => null,
            'error' => null,
            'error_kind' => null,
        ]);
    }

    /** Kemajuan per baris; pemanggil yang memproses bertahap wajib memanggil ini. */
    public function progress(FinanceOperation $operation, int $processed, ?int $total = null): void
    {
        $operation->update(array_filter([
            'processed_rows' => max(0, $processed),
            'total_rows' => $total,
        ], fn (mixed $v): bool => $v !== null));
    }

    public function complete(FinanceOperation $operation, array $result = []): void
    {
        $finished = Carbon::now();

        $operation->update([
            'status' => FinanceOperation::STATUS_COMPLETED,
            'result' => $result,
            'error' => null,
            'error_kind' => null,
            'retryable' => false,
            'finished_at' => $finished,
            'duration_ms' => $operation->started_at !== null
                ? (int) $operation->started_at->diffInMilliseconds($finished)
                : null,
            // Pekerjaan yang tidak melaporkan total tetap terlihat "sampai" di
            // layar: jumlah baris yang diproses menjadi totalnya, bukan nol.
            'total_rows' => $operation->total_rows ?? ((int) $operation->processed_rows > 0
                ? (int) $operation->processed_rows
                : null),
        ]);
    }

    /**
     * Tandai gagal. `retryable` di sini adalah pernyataan teknis: true hanya bila
     * menjalankan pekerjaan yang sama dua kali aman.
     */
    public function fail(FinanceOperation $operation, \Throwable|string $error, bool $retryable = true, array $context = []): void
    {
        $finished = Carbon::now();

        $operation->update([
            'status' => FinanceOperation::STATUS_FAILED,
            'error' => self::describe($error),
            'error_kind' => $error instanceof \Throwable ? $error::class : 'manual',
            'retryable' => $retryable,
            'context' => $context !== [] ? array_merge((array) $operation->context, $context) : $operation->context,
            'finished_at' => $finished,
            'duration_ms' => $operation->started_at !== null
                ? (int) $operation->started_at->diffInMilliseconds($finished)
                : null,
        ]);
    }

    /**
     * Catat satu peristiwa gagal di jalur nyata (§29). Tidak pernah melempar.
     *
     * Penulisannya dua tingkat: baris register bila database masih mau menerima,
     * dan selalu `Log::error` sebagai jaring terakhir. Sebuah insiden yang terjadi
     * di dalam transaksi yang kemudian di-rollback ikut hilang dari register
     * bersama aksinya — itu semantik yang benar: jejak kegagalan tidak boleh lebih
     * tahan lama daripada pekerjaan yang gagal itu sendiri, dan log aplikasi tetap
     * menyimpannya.
     *
     * @param  array{type?: string|null, id?: int|null, number?: string|null}  $reference
     */
    public function recordIncident(
        string $name,
        \Throwable|string $error,
        array $context = [],
        array $reference = [],
        bool $retryable = false,
        ?User $actor = null,
    ): ?FinanceOperation {
        $message = self::describe($error);

        Log::error('finance incident', [
            'name' => $name,
            'error' => $message,
            'context' => $context,
            'reference' => $reference,
        ]);

        try {
            return FinanceOperation::create([
                'name' => $name,
                'kind' => FinanceOperation::KIND_INCIDENT,
                'status' => FinanceOperation::STATUS_FAILED,
                'error' => $message,
                'error_kind' => $error instanceof \Throwable ? $error::class : 'manual',
                'context' => $context,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => isset($reference['id']) ? (int) $reference['id'] : null,
                'reference_number' => $reference['number'] ?? null,
                'retryable' => $retryable,
                'attempts' => 1,
                'queued_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
                'created_by' => $actor?->id,
            ]);
        } catch (\Throwable $e) {
            // Register yang gagal mencatat tidak boleh ikut menggagalkan aksi yang
            // sedang dicatat; jejak aplikasinya sudah ada di Log::error di atas.
            Log::error('finance incident tidak tercatat: '.self::describe($e), ['incident' => $name]);

            return null;
        }
    }

    /**
     * Jalankan ulang pekerjaan dari argumen yang tersimpan (§29 "can it be
     * retried?"). Aman terhadap duplikat karena service di baliknya idempoten,
     * dan baris operasinya tetap satu — `attempts` yang bertambah, bukan baris
     * baru yang membuat dua job terlihat seperti dua pekerjaan berbeda.
     */
    public function retry(FinanceOperation $operation, ?User $actor = null): FinanceOperation
    {
        if ($operation->kind !== FinanceOperation::KIND_JOB) {
            throw ValidationException::withMessages([
                'operation' => 'Peristiwa gagal tidak dapat dijalankan ulang; ulangi aksi aslinya.',
            ]);
        }

        if ($operation->status === FinanceOperation::STATUS_COMPLETED) {
            // Baris yang sudah selesai bukan antrean: memanggil pekerjaan yang
            // sama sekali lagi adalah aksi lain, dan ia memang tetap aman karena
            // service di bawahnya idempoten.
            throw ValidationException::withMessages([
                'operation' => 'Pekerjaan sudah selesai dan tidak ada yang perlu diulang. Panggil lagi pekerjaan yang sama bila periode ini memang perlu ditagih ulang.',
            ]);
        }

        if (! $operation->retryable) {
            throw ValidationException::withMessages([
                'operation' => 'Pekerjaan ini tidak ditandai boleh diulang.',
            ]);
        }

        if ($operation->isRunning()) {
            throw ValidationException::withMessages([
                'operation' => 'Pekerjaan masih berjalan; tunggu sampai selesai.',
            ]);
        }

        $class = self::JOB_CLASSES[$operation->name] ?? null;

        if ($class === null) {
            throw ValidationException::withMessages([
                'operation' => 'Jenis pekerjaan tidak lagi tersedia di sistem.',
            ]);
        }

        // Siapa yang menekan ulang dicatat di `context` (kolom baru tidak butuh
        // sejarah per percobaan: `attempts` sudah menghitungnya).
        $operation->update([
            'status' => FinanceOperation::STATUS_QUEUED,
            'queued_at' => Carbon::now(),
            'started_at' => null,
            'finished_at' => null,
            'processed_rows' => 0,
            'result' => null,
            'context' => array_merge((array) $operation->context, [
                'last_retry' => ['by' => $actor?->id, 'at' => Carbon::now()->toIso8601String()],
            ]),
        ]);

        $class::dispatch((int) $operation->id, (array) $operation->payload)->onQueue('finance');

        return $operation->fresh() ?? $operation;
    }

    private static function describe(\Throwable|string $error): string
    {
        $message = $error instanceof \Throwable
            ? $error::class.': '.$error->getMessage()
            : $error;

        return mb_substr(trim($message), 0, 4000);
    }
}
