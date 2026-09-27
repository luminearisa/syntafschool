<?php

namespace App\Console\Commands;

use App\Services\Boarding\BoardingHomecomingService;
use App\Services\Boarding\BoardingPermissionService;
use Illuminate\Console\Command;

/**
 * Kedaluwarsakan izin keluar & kepulangan yang lewat tenggat.
 *
 * Status `expired` tidak pernah tercapai tanpa perintah ini: santri yang
 * seharusnya pulang tapi tidak pernah check-in akan terbaca `out` selamanya di
 * dashboard dan monitoring. Transisinya tidak ditulis ulang di sini — dipanggil
 * jalur service yang sama dengan aksi petugas, lengkap dengan penjaga status
 * dan jejak auditnya. Idempoten: hanya baris kedaluwarsa yang berpindah status.
 */
class BoardingExpireStaleCommand extends Command
{
    protected $signature = 'boarding:expire-stale';

    protected $description = 'Tandai kedaluwarsa izin keluar dan kepulangan yang melewati batas waktu kembali';

    public function handle(BoardingPermissionService $permissions, BoardingHomecomingService $homecomings): int
    {
        $permissionsExpired = $permissions->expireStale();
        $homecomingsExpired = $homecomings->expireStale();

        $this->info("Izin keluar dikedaluwarsakan: {$permissionsExpired}");
        $this->info("Kepulangan dikedaluwarsakan: {$homecomingsExpired}");

        return self::SUCCESS;
    }
}
