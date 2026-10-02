<?php

namespace App\Modules\Hrd\Console;

use App\Modules\Hrd\Application\Services\LifecycleTransactionService;
use App\Modules\Hrd\Models\HrTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Penerapan transaksi yang sudah disetujui dan jatuh tempo (§33, §77).
 *
 * Persetujuan sebuah transfer bisa diberikan hari ini untuk berlaku 1 Juli. Perintah
 * ini yang menerapkannya pada tanggal berlaku — satu per satu, di dalam transaksi
 * basis data, dan idempoten: menjalankan ulang tidak menggandakan penugasan.
 */
class HrdApplyDueTransactionsCommand extends Command
{
    protected $signature = 'hrd:apply-due-transactions {--limit=200 : Jumlah maksimum transaksi per jalan} {--dry-run : Tampilkan tanpa menerapkan}';

    protected $description = 'Terapkan transaksi SDM yang sudah disetujui dan tanggal berlakunya telah tiba.';

    public function handle(LifecycleTransactionService $transactions): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $rows = $transactions->dueTransactions(now()->toDateString(), $limit)->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada transaksi yang jatuh tempo.');

            return self::SUCCESS;
        }

        $applied = 0;
        $failed = 0;

        foreach ($rows as $transaction) {
            if ($dryRun) {
                $this->line("Akan diterapkan: {$transaction->transaction_number} ({$transaction->type_code}) berlaku {$transaction->effective_date?->toDateString()}");

                continue;
            }

            try {
                // Sistem bertindak sebagai dirinya sendiri: jejak audit mencatat
                // admin sistem, bukan pengguna yang kebetulan pertama di tabel.
                $actorId = (int) ($transaction->decided_by ?? $transaction->requested_by ?? DB::table('users')->min('id'));
                $actor = \App\Models\User::query()->find($actorId);

                if ($actor === null) {
                    throw new \RuntimeException('Tidak ada pengguna yang dapat dicatat sebagai pelaksana penerapan.');
                }

                $transactions->applyTransaction($transaction, $actor);
                $applied++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Gagal menerapkan {$transaction->transaction_number}: ".$exception->getMessage());
            }
        }

        $this->info("Selesai. Diterapkan: {$applied}, gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Transaksi yang menunggu jadwal berikutnya — untuk verifikasi manual. */
    public function pendingCount(): int
    {
        return HrTransaction::query()->where('status', 'approved')->whereNull('applied_at')->count();
    }
}
