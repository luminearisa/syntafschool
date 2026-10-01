<?php

namespace App\Modules\Workflow\Domain\Support;

use App\Modules\Workflow\Domain\Enums\ApproverSource;

/**
 * Hasil resolusi satu approver (§22).
 *
 * `originalUserId` dan `delegationId` sengaja dipisah dari `userId`: ketika
 * seorang manajer mendelegasikan kewenangannya, identitas asli tetap tercatat
 * sehingga riwayat approval tidak pernah kehilangan nama pemilik kewenangan
 * yang sebenarnya (§8).
 */
final class ResolvedApprover
{
    public function __construct(
        public readonly int $userId,
        public readonly ApproverSource $source,
        public readonly string $resolver,
        public readonly array $spec = [],
        public readonly ?int $originalUserId = null,
        public readonly ?int $delegationId = null,
        public readonly bool $isDelegate = false,
        public readonly ?string $note = null,
    ) {
    }

    public function isSelfAssignment(int $requesterId): bool
    {
        return $this->userId === $requesterId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'source' => $this->source->value,
            'resolver' => $this->resolver,
            'spec' => $this->spec,
            'original_user_id' => $this->originalUserId,
            'delegation_id' => $this->delegationId,
            'is_delegate' => $this->isDelegate,
            'note' => $this->note,
        ];
    }
}
