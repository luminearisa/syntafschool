<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Survei.
 *
 * Modul Survei belum terpasang; evaluasi pasca event dicatat langsung di modul Event (§76).
 */
class NullSurveyProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Modul Survei belum terpasang; evaluasi pasca event dicatat langsung di modul Event (§76).';

    protected const TARGET_CONTRACT = null;

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function create(array $payload): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }

    /** Tidak mengarang data: melaporkan ketidaktersediaan apa adanya. */
    public function results(int $surveyId): array
    {
        return ['written' => false, 'reason' => self::UNAVAILABLE_REASON];
    }
}
