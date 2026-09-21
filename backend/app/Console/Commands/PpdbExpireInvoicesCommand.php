<?php

namespace App\Console\Commands;

use App\Services\Ppdb\PpdbInvoiceService;
use Illuminate\Console\Command;

/**
 * Kedaluwarsakan tagihan PPDB yang lewat jatuh tempo.
 *
 * Tanpa perintah ini status `expired` tidak pernah tercapai: tagihan jatuh tempo
 * akan terus terbaca `unpaid`/`pending` di portal, daftar piutang, dan dashboard
 * sampai panitia menanganinya manual. Aturan transisinya tidak ditulis ulang di
 * sini — perintah ini hanya memanggil jalur yang sama dengan aksi panitia
 * (`PpdbInvoiceService::expire()`), sehingga penjaga status dan jejak auditnya
 * ikut berlaku.
 *
 * Idempoten: yang diambil hanya tagihan `unpaid`/`pending`, dan setiap tagihan
 * yang diproses berpindah ke `expired`, jadi dijalankan berulang tidak membuat
 * apa pun menjadi dua. Bisa dibatasi per periode untuk penagihan bertahap.
 */
class PpdbExpireInvoicesCommand extends Command
{
    protected $signature = 'ppdb:expire-invoices {--period= : Batasi pada satu id periode PPDB}';

    protected $description = 'Tandai kedaluwarsa tagihan PPDB yang melewati jatuh tempo tanpa pembayaran';

    public function handle(PpdbInvoiceService $invoices): int
    {
        $periodId = $this->option('period') !== null ? (int) $this->option('period') : null;

        $result = $invoices->expireOverdue($periodId);

        $this->info("Tagihan PPDB dikedaluwarsakan: {$result['expired']}");
        $this->info("Tagihan diperiksa: {$result['scanned']}");

        foreach ($result['skipped'] as $skipped) {
            $this->warn("Dilewati {$skipped['number']} (id {$skipped['invoice_id']}): {$skipped['reason']}");
        }

        if ($result['skipped'] !== []) {
            $this->info('Tagihan yang dilewatkan perlu diputus manual: batalkan atau catat pengembalian dana.');
        }

        return self::SUCCESS;
    }
}
