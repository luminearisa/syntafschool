<?php

namespace App\Modules\Hrd\Application\Support;

use App\Modules\Hrd\Models\HrIntegrationOutbox;
use Illuminate\Support\Facades\Log;

/**
 * Outbox integrasi (§66, §77).
 *
 * Inilah sebab modul HRD tidak menuntut seluruh modul ERP terpasang. Setiap niat
 * untuk memberi tahu modul lain (Aset, Keuangan, Workflow, LMS, Presensi, Akun)
 * ditulis lebih dulu sebagai baris `pending`, lalu dikirim oleh penjadwal atau
 * perintah artisan. Modul tujuan yang belum ada tidak membuat peristiwa hilang —
 * hanya tertunda, dan tetap terlihat pada layar kesehatan modul.
 *
 * Handler terdaftar per `module.action`; menambah integrasi baru berarti
 * mendaftarkan satu penutup (closure) di service provider, bukan menyunting
 * mesin transaksi.
 */
class IntegrationOutbox
{
    /** @var array<string, callable(array<string, mixed>, HrIntegrationOutbox): bool> */
    private array $handlers = [];

    /**
     * Daftarkan penangan untuk satu modul+aksi.
     *
     * Penangan mengembalikan `true` bila berhasil, `false` bila modul tujuan
     * belum siap (baris kembali menjadi `pending`), atau melempar pengecualian
     * bila gagal (baris menjadi `failed` dengan catatan galat).
     *
     * @param  callable(array<string, mixed>, HrIntegrationOutbox): bool  $handler
     */
    public function register(string $module, string $action, callable $handler): void
    {
        $this->handlers["{$module}.{$action}"] = $handler;
    }

    /** @return list<string> */
    public function registeredActions(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Catat niat integrasi. Tidak pernah menggagalkan transaksi pemanggil.
     *
     * @param  array<string, mixed>  $payload
     */
    public function push(string $module, string $action, array $payload = [], ?string $subjectType = null, int|string|null $subjectId = null, int $delaySeconds = 0): HrIntegrationOutbox
    {
        return HrIntegrationOutbox::query()->create([
            'module' => $module,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (int) $subjectId,
            'payload' => $payload,
            'status' => HrIntegrationOutbox::STATUS_PENDING,
            'available_at' => $delaySeconds > 0 ? now()->addSeconds($delaySeconds) : now(),
        ]);
    }

    /**
     * Kirim sejumlah baris yang sudah waktunya.
     *
     * @return array{pending: int, dispatched: int, skipped: int, failed: int}
     */
    public function dispatch(int $limit = 100): array
    {
        $stats = ['pending' => 0, 'dispatched' => 0, 'skipped' => 0, 'failed' => 0];

        $rows = HrIntegrationOutbox::query()->pending()->orderBy('id')->limit($limit)->get();

        foreach ($rows as $row) {
            $handler = $this->handlers["{$row->module}.{$row->action}"] ?? null;

            if ($handler === null) {
                // Modul tujuan belum terpasang: biarkan menunggu, jangan menandai gagal.
                $stats['pending']++;
                $row->forceFill(['attempts' => (int) $row->attempts + 1])->save();

                continue;
            }

            try {
                $ok = $handler($row->payload ?? [], $row);

                if ($ok) {
                    $row->forceFill([
                        'status' => HrIntegrationOutbox::STATUS_DISPATCHED,
                        'dispatched_at' => now(),
                        'attempts' => (int) $row->attempts + 1,
                        'last_error' => null,
                    ])->save();

                    $stats['dispatched']++;

                    continue;
                }

                $stats['skipped']++;
                $row->forceFill([
                    'attempts' => (int) $row->attempts + 1,
                    'last_error' => 'Modul tujuan belum siap menerima peristiwa ini.',
                ])->save();
            } catch (\Throwable $exception) {
                $attempts = (int) $row->attempts + 1;
                $maxAttempts = (int) config('hrd.integration.max_attempts', 5);

                $row->forceFill([
                    'status' => $attempts >= $maxAttempts ? HrIntegrationOutbox::STATUS_FAILED : HrIntegrationOutbox::STATUS_PENDING,
                    'attempts' => $attempts,
                    'last_error' => mb_substr($exception->getMessage(), 0, 500),
                    'available_at' => now()->addMinutes(min(60, 2 ** $attempts)),
                ])->save();

                $stats['failed']++;
                Log::warning('HRD outbox dispatch gagal', ['id' => $row->getKey(), 'error' => $exception->getMessage()]);
            }
        }

        return $stats;
    }

    /** Ringkasan keadaan outbox untuk layar kesehatan modul. */
    public function status(): array
    {
        $rows = HrIntegrationOutbox::query()
            ->selectRaw('module, status, count(*) as aggregate')
            ->groupBy('module', 'status')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $summary[$row->module][$row->status] = (int) $row->aggregate;
        }

        return $summary;
    }

    public function retry(int $outboxId): bool
    {
        return HrIntegrationOutbox::query()
            ->whereKey($outboxId)
            ->whereIn('status', [HrIntegrationOutbox::STATUS_FAILED, HrIntegrationOutbox::STATUS_SKIPPED])
            ->update([
                'status' => HrIntegrationOutbox::STATUS_PENDING,
                'available_at' => now(),
                'last_error' => null,
            ]) > 0;
    }
}
