<?php

namespace App\Console\Commands;

use App\Models\Library;
use App\Services\Library\Circulation\CirculationService;
use App\Services\Library\Circulation\ReservationService;
use App\Services\Library\LibraryNotificationService;
use App\Services\Library\LibrarySettingsService;
use App\Services\Library\Membership\MembershipService;
use Illuminate\Console\Command;

/**
 * Perawatan harian modul Library (§10).
 *
 * Urutan penting: tandai terlambat & nilai denda lebih dulu, baru kirim
 * pengingat — agar notifikasi keterlambatan mencerminkan keadaan terbaru.
 * Semua langkah idempoten sehingga aman dijalankan berulang.
 */
class LibraryDailyMaintenanceCommand extends Command
{
    protected $signature = 'library:daily-maintenance {--library= : Batasi pada satu id perpustakaan}';

    protected $description = 'Tandai keterlambatan, nilai denda, kedaluwarsakan reservasi & keanggotaan, lalu kirim pengingat';

    public function handle(
        CirculationService $circulation,
        ReservationService $reservations,
        MembershipService $members,
        LibraryNotificationService $notifications,
        LibrarySettingsService $settings,
    ): int {
        $libraryId = $this->option('library') !== null ? (int) $this->option('library') : null;

        $marksLoans = $circulation->markOverdue($libraryId);
        $this->info("Pinjaman ditandai terlambat: {$marksLoans}");

        $expiredReservations = $reservations->expireLapsed($libraryId);
        $this->info("Reservasi kedaluwarsa: {$expiredReservations}");

        $expiredMembers = $members->evaluateExpirations($libraryId);
        $this->info("Keanggotaan kedaluwarsa: {$expiredMembers}");

        $reminders = 0;

        // Pengingat H- mengikuti konfigurasi perpustakaan (default 2 hari).
        $targets = $libraryId !== null
            ? Library::query()->whereKey($libraryId)->get()
            : Library::query()->get();

        foreach ($targets as $library) {
            $daysBefore = max(1, (int) $settings->get($library, 'loan.reminder_days_before', 2));

            $reminders += $notifications->remindDueSoon($library->id, $daysBefore);
            $reminders += $notifications->remindOverdue($library->id);
            $reminders += $notifications->notifyReservationsAvailable($library->id);
            $settings->ensureDefaults($library);
        }

        $reminders += $notifications->remindMembershipExpiring($libraryId);

        $this->info("Notifikasi terkirim: {$reminders}");

        return self::SUCCESS;
    }
}
