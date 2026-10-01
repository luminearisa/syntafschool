<?php

namespace App\Modules\Asset\Console;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetNotificationService;
use App\Modules\Asset\Application\Services\InspectionService;
use App\Modules\Asset\Application\Services\AssetLoanService;
use App\Modules\Asset\Application\Services\PreventiveMaintenanceService;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetNotification;
use App\Modules\Asset\Models\AssetStockOpname;
use Illuminate\Console\Command;

/**
 * Pengingat operasional: garansi, inspeksi, pemeliharaan, pinjaman terlambat,
 * dan jadwal stok opname.
 *
 * Idempoten per hari: satu pengingat untuk satu hal yang sama tidak dikirim dua
 * kali pada hari yang sama. Pengingat yang berulang tanpa kendali akan diabaikan
 * orang — dan pengingat yang diabaikan lebih buruk daripada tidak ada pengingat.
 */
class AssetSendRemindersCommand extends Command
{
    protected $signature = 'asset:reminders';

    protected $description = 'Kirim pengingat garansi, inspeksi, pemeliharaan, pinjaman terlambat, dan jadwal opname.';

    public function handle(
        AssetNotificationService $notifications,
        InspectionService $inspections,
        PreventiveMaintenanceService $preventive,
        AssetLoanService $loans,
    ): int {
        $windows = (array) config('asset.reminders.warranty_days', [90, 60, 30]);
        $sent = 0;

        // 1. Garansi akan berakhir.
        foreach ($windows as $days) {
            $target = now()->addDays((int) $days)->toDateString();

            foreach (Asset::query()->whereDate('warranty_end', $target)->get() as $asset) {
                $sent += $this->once($notifications, 'ASSET_WARRANTY_EXPIRING', $asset, 'Garansi segera berakhir: '.$asset->name,
                    sprintf('Garansi berakhir %s (%d hari lagi).', $asset->warranty_end?->toDateString(), (int) $days));
            }
        }

        // 2. Inspeksi akan jatuh tempo.
        foreach ($inspections->dueSoon((int) config('asset.reminders.inspection_due_days', 7)) as $row) {
            $asset = Asset::query()->find($row['asset_id']);

            if ($asset !== null) {
                $sent += $this->once($notifications, 'ASSET_INSPECTION_DUE', $asset, 'Inspeksi segera jatuh tempo: '.$asset->name,
                    'Jadwal inspeksi '.$row['next_inspection_at'].'.');
            }
        }

        // 3. Pemeliharaan preventif akan jatuh tempo.
        foreach ($preventive->dueSoon((int) config('asset.reminders.maintenance_due_days', 7)) as $row) {
            $asset = $row['asset_id'] !== null ? Asset::query()->find($row['asset_id']) : null;

            $sent += $this->once($notifications, 'ASSET_MAINTENANCE_DUE', $asset, 'Pemeliharaan segera jatuh tempo: '.$row['title'],
                'Jadwal '.$row['next_due_at'].'.');
        }

        // 4. Pinjaman terlambat.
        foreach ($loans->markOverdue() as $number) {
            $this->line('  ! pinjaman terlambat: '.$number);
            $sent++;
        }

        // 5. Stok opname yang sudah melewati siklus.
        $cycle = (int) config('asset.reminders.stock_opname_cycle_days', 180);
        $lastOpname = AssetStockOpname::query()->where('status', 'COMPLETED')->max('finished_at');

        if ($lastOpname === null || \Illuminate\Support\Carbon::parse($lastOpname)->lt(now()->subDays($cycle))) {
            $sent += $this->once($notifications, 'ASSET_STOCK_OPNAME_DUE', null, 'Stok opname belum dilakukan',
                sprintf('Siklus opname %d hari; terakhir: %s.', $cycle, $lastOpname ?? 'belum pernah'));
        }

        $this->info('Pengingat terkirim: '.$sent.'.');

        return self::SUCCESS;
    }

    /** Kirim sekali per hari untuk subjek & peristiwa yang sama. */
    protected function once(AssetNotificationService $notifications, string $event, ?Asset $asset, string $title, string $body): int
    {
        $already = AssetNotification::query()
            ->where('event', $event)
            ->where('subject_type', $asset?->getMorphClass())
            ->where('subject_id', $asset?->id)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($already) {
            return 0;
        }

        $notifications->notify(
            array_values(array_filter([$asset?->custodian_id])),
            ['ASSET_OFFICER', 'MAINTENANCE_MANAGER'],
            $event,
            $title,
            $body,
            $asset,
        );

        $this->line('  → '.$title);

        return 1;
    }
}
