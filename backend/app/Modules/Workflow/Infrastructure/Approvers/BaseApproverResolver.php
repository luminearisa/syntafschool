<?php

namespace App\Modules\Workflow\Infrastructure\Approvers;

use App\Modules\Workflow\Application\Services\DelegationService;
use App\Modules\Workflow\Domain\Contracts\ApproverResolverInterface;

/**
 * Base resolver: memvalidasi spesifikasi, lalu menyerahkan ke implementasi.
 */
abstract class BaseApproverResolver implements ApproverResolverInterface
{
    /** @param array<string, mixed> $spec */
    public function missingSpecKeys(array $spec): array
    {
        $missing = [];

        foreach ($this->requiredSpec() as $key => $type) {
            if (! array_key_exists($key, $spec) || $spec[$key] === null || $spec[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Nilai spesifikasi sebagai daftar (mendukung string tunggal maupun array).
     *
     * @return list<mixed>
     */
    protected function listValue(array $spec, string $key): array
    {
        $value = $spec[$key] ?? [];

        if ($value === null) {
            return [];
        }

        return is_array($value) ? array_values($value) : [$value];
    }
}
