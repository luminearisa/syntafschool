<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Keadaan transaksi SDM (§33).
 *
 * Perjalanan yang diizinkan:
 *
 * ```
 * draft ─▶ submitted ─▶ review ─▶ approved ─▶ completed
 *   │          │           │          │
 *   └──────────┴───────────┴──────────┴──▶ cancelled
 *                │           │
 *                └───────────┴──────────▶ rejected
 * ```
 *
 * `completed` hanya dicapai setelah efek transaksi benar-benar diterapkan
 * (penugasan baru dibuat, penugasan lama ditutup) — bukan setelah disetujui.
 * Perbedaan itu penting: persetujuan bukan perubahan.
 */
enum TransactionStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case REVIEW = 'review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::REVIEW => 'Ditinjau',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::COMPLETED => 'Selesai',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::SUBMITTED, self::CANCELLED],
            self::SUBMITTED => [self::REVIEW, self::APPROVED, self::REJECTED, self::CANCELLED],
            self::REVIEW => [self::APPROVED, self::REJECTED, self::CANCELLED],
            // Disetujui tetapi belum diterapkan: masih boleh dibatalkan sebelum
            // tanggal berlaku, dan hanya boleh maju ke `completed`.
            self::APPROVED => [self::COMPLETED, self::CANCELLED],
            self::REJECTED, self::CANCELLED, self::COMPLETED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::DRAFT, self::SUBMITTED, self::REVIEW], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::REJECTED, self::CANCELLED, self::COMPLETED], true);
    }
}
