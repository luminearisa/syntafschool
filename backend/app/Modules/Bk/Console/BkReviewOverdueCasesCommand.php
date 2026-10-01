<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkDashboardService;
use App\Modules\Bk\Application\Services\BkNotificationService;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use Illuminate\Console\Command;

/**
 * Laporkan kasus yang tenggat tinjauannya sudah lewat.
 *
 * Konselor menerima daftar kasusnya sendiri; koordinator menerima ringkasan
 * sekolah. Isi kasus tidak ikut — hanya nomor dan lama menunggu.
 */
class BkReviewOverdueCasesCommand extends Command
{
    protected $signature = 'bk:review-overdue-cases';

    protected $description = 'Beri tahu konselor tentang kasus yang melewati tenggat tinjauan.';

    public function handle(BkDashboardService $dashboard, BkNotificationService $notifications): int
    {
        $cases = $dashboard->agingCases(50);
        $overdue = collect($cases)->filter(fn (array $case): bool => $case['review_overdue'] === true);

        if ($overdue->isEmpty()) {
            $this->info('Tidak ada kasus yang melewati tenggat tinjauan.');

            return self::SUCCESS;
        }

        $notifications->notifyTeam(
            NotificationEvent::FOLLOW_UP_DUE,
            sprintf('%d kasus melewati tenggat tinjauan pada rentang ini.', $overdue->count()),
            ['cases' => $overdue->pluck('case_number')->all()],
        );

        $this->info(sprintf('Kasus melewati tenggat tinjauan: %d.', $overdue->count()));

        return self::SUCCESS;
    }
}
