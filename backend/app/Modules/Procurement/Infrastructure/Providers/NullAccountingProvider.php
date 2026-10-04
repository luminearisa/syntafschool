<?php

namespace App\Modules\Procurement\Infrastructure\Providers;

use App\Modules\Procurement\Domain\Contracts\AccountingProviderInterface;
use App\Modules\Procurement\Domain\Support\IntegrationResult;

/**
 * Implementasi bawaan bila modul Accounting belum terpasang.
 *
 * Ia **tidak** menandai handoff gagal permanen: ia mengembalikan kode
 * `PROVIDER_NOT_INSTALLED` sehingga pemanggil menyimpan permintaan sebagai
 * PENDING di `proc_integrations` — niat tidak hilang dan dapat dikirim ulang
 * saat modul Accounting dipasang (§32, §33).
 */
class NullAccountingProvider implements AccountingProviderInterface
{
    public const CODE = 'PROVIDER_NOT_INSTALLED';

    public function createPayable(array $payload): IntegrationResult
    {
        return IntegrationResult::failure(
            'Modul Accounting belum terpasang; permintaan dicatat untuk dikirim ulang.',
            self::CODE,
        );
    }

    public function getInvoiceStatus(string $reference): ?array
    {
        return null;
    }

    public function getPaymentStatus(string $reference): ?array
    {
        return null;
    }
}
