<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Cuaca.
 *
 * Penyedia cuaca belum dikonfigurasi; ketergantungan cuaca hanya dicatat sebagai penanda pada rencana kontingensi (§62).
 */
class NullWeatherProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Penyedia cuaca belum dikonfigurasi; ketergantungan cuaca hanya dicatat sebagai penanda pada rencana kontingensi (§62).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function forecast(string $date, ?string $location = null): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
