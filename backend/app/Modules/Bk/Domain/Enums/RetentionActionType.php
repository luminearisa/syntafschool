<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tindakan retensi pada satu rekaman.
 *
 * Perjalanannya Active -> Archived -> Retention Expired -> Approved Disposal, dan
 * setiap langkah punya barisnya sendiri sehingga pemusnahan dapat diaudit.
 */
enum RetentionActionType: string
{
    case ARCHIVED = 'ARCHIVED';
    case RESTORED = 'RESTORED';
    case DISPOSAL_REQUESTED = 'DISPOSAL_REQUESTED';
    case DISPOSAL_APPROVED = 'DISPOSAL_APPROVED';
    case DISPOSAL_REJECTED = 'DISPOSAL_REJECTED';
    case DISPOSED = 'DISPOSED';
    case EXPORTED = 'EXPORTED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ARCHIVED => 'Diarsipkan',
            self::RESTORED => 'Dipulihkan',
            self::DISPOSAL_REQUESTED => 'Permintaan pemusnahan',
            self::DISPOSAL_APPROVED => 'Pemusnahan disetujui',
            self::DISPOSAL_REJECTED => 'Pemusnahan ditolak',
            self::DISPOSED => 'Dimusnahkan',
            self::EXPORTED => 'Diekspor sebelum retensi',
        };
    }

    public function disposesData(): bool
    {
        return $this === self::DISPOSED;
    }

    public function requiresApproval(): bool
    {
        return $this === self::DISPOSAL_REQUESTED;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
