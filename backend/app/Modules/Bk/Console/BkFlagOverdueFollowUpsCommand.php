<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkFollowUpService;
use Illuminate\Console\Command;

/**
 * Tandai tindak lanjut yang melewati tenggat dan beri tahu pemiliknya.
 *
 * Dijadwalkan setiap pagi sehingga papan tindak lanjut selalu jujur: yang
 * terlambat tampak terlambat, bukan tersembunyi di antara yang belum jatuh tempo.
 */
class BkFlagOverdueFollowUpsCommand extends Command
{
    protected $signature = 'bk:flag-overdue-follow-ups {--today= : Tanggal acuan (YYYY-MM-DD)}';

    protected $description = 'Tandai tindak lanjut BK yang terlambat dan kirim pemberitahuan.';

    public function handle(BkFollowUpService $followUps): int
    {
        $result = $followUps->flagOverdue($this->option('today'));

        $this->info(sprintf('Tindak lanjut terlambat: %d ditandai, %d pemberitahuan dikirim.', $result['flagged'], $result['notified']));

        return self::SUCCESS;
    }
}
