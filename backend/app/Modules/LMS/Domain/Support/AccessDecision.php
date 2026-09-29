<?php

namespace App\Modules\LMS\Domain\Support;

/**
 * Hasil pemeriksaan akses (§20). Alasan selalu ikut dikembalikan supaya UI
 * dapat menjelaskan kenapa sebuah item terkunci ("🔒 Selesaikan aktivitas
 * sebelumnya") alih-alih sekadar menolak.
 */
final readonly class AccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason = 'allowed',
        public ?string $message = null,
        /** Item prasyarat yang belum tuntas, bila ada. */
        public ?int $blockedByItemId = null,
        /** Course prasyarat yang belum tuntas (§18), bila ada. */
        public ?int $blockedByCourseId = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(
        string $reason,
        ?string $message = null,
        ?int $blockedByItemId = null,
        ?int $blockedByCourseId = null,
    ): self {
        return new self(false, $reason, $message, $blockedByItemId, $blockedByCourseId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'message' => $this->message,
            'blocked_by_item_id' => $this->blockedByItemId,
            'blocked_by_course_id' => $this->blockedByCourseId,
        ];
    }
}
