<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Application\Services\ReferenceService;
use App\Modules\Hrd\Domain\Contracts\HrReferenceProviderInterface;

/**
 * Referensi yang dapat dikonfigurasi untuk modul lain (§18, §41, §65) — supaya
 * daftar pilihan di layar modul lain tidak pernah berbeda dari daftar HRD.
 */
class ReferenceModuleProvider implements HrReferenceProviderInterface
{
    public function __construct(private readonly ReferenceService $references) {}

    public function items(string $groupCode): array
    {
        return $this->references->items($groupCode);
    }

    public function label(string $groupCode, ?string $code): ?string
    {
        return $this->references->label($groupCode, $code);
    }

    public function isValid(string $groupCode, ?string $code): bool
    {
        return $this->references->isValid($groupCode, $code);
    }

    public function defaultCode(string $groupCode): ?string
    {
        return $this->references->defaultCode($groupCode);
    }
}
