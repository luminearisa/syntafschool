<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Modules\Clinic\Domain\Contracts\FinanceProviderInterface;

/**
 * Implementasi default: layanan kesehatan tidak ditagihkan.
 *
 * Ini pilihan sadar untuk mayoritas sekolah (UKS gratis). Modul Clinic tetap
 * memanggil kontraknya sehingga ketika sekolah ingin menagih layanan tertentu,
 * cukup mengganti binding ke implementasi Finance tanpa mengubah alur klinis.
 * Tidak ada jurnal akuntansi yang dibuat dari sisi Clinic.
 */
class NullFinanceProvider implements FinanceProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function billService(array $payload): array
    {
        return [
            'status' => self::NOT_BILLABLE,
            'invoice_id' => null,
            'invoice_number' => null,
            'message' => 'Sekolah tidak menagihkan layanan kesehatan; tidak ada tagihan dibuat.',
        ];
    }

    public function billableServices(): array
    {
        return [];
    }
}
