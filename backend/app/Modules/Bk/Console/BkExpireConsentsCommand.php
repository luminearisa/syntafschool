<?php

namespace App\Modules\Bk\Console;

use App\Modules\Bk\Application\Services\BkConsentService;
use Illuminate\Console\Command;

/**
 * Tutup persetujuan orang tua yang kedaluwarsa.
 *
 * Status yang dibiarkan "APPROVED" padahal masa berlakunya sudah lewat adalah
 * kebohongan administratif; perintah ini menutupnya agar layar tidak menipu.
 */
class BkExpireConsentsCommand extends Command
{
    protected $signature = 'bk:expire-consents';

    protected $description = 'Tandai persetujuan BK yang sudah kedaluwarsa.';

    public function handle(BkConsentService $consents): int
    {
        $this->info(sprintf('Persetujuan kedaluwarsa: %d.', $consents->expireStale()));

        return self::SUCCESS;
    }
}
