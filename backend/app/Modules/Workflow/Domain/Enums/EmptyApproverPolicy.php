<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Kebijakan ketika resolusi approver tidak menghasilkan siapa pun (§58, §59).
 *
 * Tidak ada opsi "lewati diam-diam" — itu satu-satunya hal yang dilarang tegas.
 */
enum EmptyApproverPolicy: string
{
    /** Instance ditandai FAILED, admin diberi tahu, request tidak hilang. */
    case FAIL = 'FAIL';
    /** Naikkan ke atasan approver yang seharusnya (atau workflow manager). */
    case ESCALATE = 'ESCALATE';
    /** Pakai approver cadangan yang didefinisikan (`fallback_approvers`). */
    case USE_FALLBACK = 'USE_FALLBACK';
    /** Tahan step di WAITING_FOR_APPROVER sampai admin menetapkan approver. */
    case PAUSE = 'PAUSE';

    public function label(): string
    {
        return match ($this) {
            self::FAIL => 'Gagalkan Request (perlu tindakan admin)',
            self::ESCALATE => 'Eskalasi ke Atasan',
            self::USE_FALLBACK => 'Pakai Approver Cadangan',
            self::PAUSE => 'Tahan & Tunggu Penetapan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
