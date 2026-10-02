<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Katering.
 *
 * Modul Katering belum terpasang; kebutuhan makan dicatat sebagai permintaan yang dapat diteruskan (§48).
 */
class NullCateringProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Katering belum terpasang; kebutuhan makan dicatat sebagai permintaan yang dapat diteruskan (§48).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function requestMeal(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function confirmProduction(int $requestId, array $payload = []): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function status(int $requestId): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
