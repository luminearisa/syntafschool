<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Kegiatan pembelajaran (modul LMS).
 *
 * Meneruskan ke kontrak LMS agar kegiatan belajar terkait event tetap dikelola LMS (§107).
 */
class ActivityLmsProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak LMS agar kegiatan belajar terkait event tetap dikelola LMS (§107).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\LmsProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function course(int $courseId): ?array
    {
        $value = $this->call('course', [$courseId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function enroll(int $courseId, array $studentIds, array $options = []): array
    {
        $value = $this->call('enroll', [$courseId, $studentIds, $options]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function unenroll(int $courseId, array $studentIds): array
    {
        $value = $this->call('unenroll', [$courseId, $studentIds]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function progress(int $courseId, int $studentId): ?array
    {
        $value = $this->call('progress', [$courseId, $studentId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isAvailable(): bool
    {
        $value = $this->call('isAvailable', []);

        return $value === null ? false : (bool) $value;
    }
}
