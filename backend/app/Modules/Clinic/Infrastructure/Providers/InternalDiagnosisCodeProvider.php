<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Modules\Clinic\Domain\Contracts\DiagnosisCodeProviderInterface;
use App\Modules\Clinic\Models\ClinicDiagnosisMaster;

/**
 * Sumber kode diagnosis bawaan: master internal sekolah.
 *
 * Sekolah yang ingin memakai ICD-10/ICD-11 cukup menulis satu implementasi
 * kontrak ini dan mengganti binding-nya; layar klinis tidak berubah karena
 * selalu mencari lewat `search()` dan `find()`.
 */
class InternalDiagnosisCodeProvider implements DiagnosisCodeProviderInterface
{
    public function systemName(): string
    {
        return 'INTERNAL';
    }

    public function search(string $term, int $limit = 20): array
    {
        return ClinicDiagnosisMaster::query()
            ->where('is_active', true)
            ->search(trim($term))
            ->orderBy('code')
            ->limit($limit)
            ->get()
            ->map(fn (ClinicDiagnosisMaster $row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'category' => $row->category,
            ])
            ->all();
    }

    public function find(string $code): ?array
    {
        $row = ClinicDiagnosisMaster::query()->where('code', $code)->first();

        return $row === null ? null : [
            'code' => $row->code,
            'name' => $row->name,
            'category' => $row->category,
        ];
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
