<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\AssetProviderInterface;
use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Implementasi bawaan bila modul Asset belum terpasang.
 *
 * Perolehan aset dicatat sebagai PENDING sehingga dapat didaftarkan kemudian
 * tanpa kehilangan jejak (§26, §52.14).
 */
class NullAssetProvider implements AssetProviderInterface
{
    public const CODE = 'PROVIDER_NOT_INSTALLED';

    public function registerAcquisition(array $payload): IntegrationResult
    {
        return IntegrationResult::failure(
            'Modul Asset belum terpasang; pendaftaran aset dicatat sebagai PENDING.',
            self::CODE,
        );
    }
}
