<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Konteks akademik (modul Activity).
 *
 * Meneruskan ke kontrak Academic untuk konteks tingkat/kelas dan pengakuan prestasi (§19).
 */
class ActivityAcademicProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Academic untuk konteks tingkat/kelas dan pengakuan prestasi (§19).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\AcademicProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function context(): array
    {
        $value = $this->call('context', []);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function classLevel(int $studentId): ?int
    {
        $value = $this->call('classLevel', [$studentId]);

        return $value === null ? null : (int) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function programName(int $studentId): ?string
    {
        $value = $this->call('programName', [$studentId]);

        return $value === null ? null : (string) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function standing(int $studentId): array
    {
        $value = $this->call('standing', [$studentId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function submitRecognition(int $studentId, array $payload): array
    {
        $value = $this->call('submitRecognition', [$studentId, $payload]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function reportCardSummary(int $studentId, ?int $periodId = null): array
    {
        $value = $this->call('reportCardSummary', [$studentId, $periodId]);

        return $this->wrap($value);
    }
}
