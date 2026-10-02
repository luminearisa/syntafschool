<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Sertifikat (modul Certificate).
 *
 * Meneruskan ke kontrak Certificate; penerbitan tetap mengikuti aturan kelayakan (§74, §75).
 */
class ActivityCertificateProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak Certificate; penerbitan tetap mengikuti aturan kelayakan (§74, §75).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Activity\\Domain\\Contracts\\CertificateProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function render(array $payload): array
    {
        $value = $this->call('render', [$payload]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function revoke(string $path): bool
    {
        $value = $this->call('revoke', [$path]);

        return $value === null ? false : (bool) $value;
    }
}
