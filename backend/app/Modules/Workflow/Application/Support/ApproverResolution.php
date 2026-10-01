<?php

namespace App\Modules\Workflow\Application\Support;

use App\Modules\Workflow\Domain\Support\ResolvedApprover;

/**
 * Hasil resolusi approver untuk satu node.
 *
 * Selalu membawa **peringatan** beserta hasilnya. Ketika tidak ada satu pun
 * approver ditemukan, engine tidak boleh diam: peringatan inilah yang muncul
 * di dasbor admin, pada respons API, dan pada hasil simulasi (§58).
 */
final class ApproverResolution
{
    /**
     * @param  list<ResolvedApprover>  $approvers
     * @param  list<string>  $warnings
     * @param  list<list<int>>  $tiers
     */
    public function __construct(
        public readonly array $approvers,
        public readonly array $warnings = [],
        public readonly array $tiers = [],
    ) {
    }

    public static function empty(array $warnings = []): self
    {
        return new self([], $warnings);
    }

    public function isEmpty(): bool
    {
        return $this->approvers === [];
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return array_values(array_map(fn (ResolvedApprover $a) => $a->userId, $this->approvers));
    }

    /** @return list<list<int>> id pengguna per tingkat (untuk mode HYBRID) */
    public function tierUserIds(): array
    {
        if ($this->tiers === []) {
            return [$this->userIds()];
        }

        return $this->tiers;
    }

    public function count(): int
    {
        return count($this->approvers);
    }
}
