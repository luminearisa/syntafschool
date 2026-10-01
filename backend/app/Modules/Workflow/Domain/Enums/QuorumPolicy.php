<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Kebijakan kuorum untuk persetujuan paralel (§20, §60).
 */
enum QuorumPolicy: string
{
    case ALL = 'ALL';
    case ANY = 'ANY';
    case MINIMUM_N = 'MINIMUM_N';
    case PERCENTAGE = 'PERCENTAGE';

    /**
     * Berapa suara setuju yang dibutuhkan dari sejumlah approver.
     *
     * @param  int  $total  jumlah approver yang ditugaskan
     * @param  int  $value  nilai `quorum_value` pada node (N atau persen)
     */
    public function requiredApprovals(int $total, int $value = 0): int
    {
        if ($total <= 0) {
            return 0;
        }

        return match ($this) {
            self::ALL => $total,
            self::ANY => 1,
            self::MINIMUM_N => min(max(1, $value), $total),
            self::PERCENTAGE => min($total, max(1, (int) ceil($total * max(1, $value) / 100))),
        };
    }

    /** Jumlah penolakan yang sudah cukup untuk menghentikan step. */
    public function rejectionThreshold(int $total, int $value = 0): int
    {
        return match ($this) {
            // Seorang penolak membatalkan seluruh cabang paralel (§20).
            self::ALL, self::ANY => 1,
            // Untuk MINIMUM_N/PERCENTAGE, penolakan baru menentukan ketika
            // sisa suara tidak lagi cukup untuk memenuhi kuorum setuju.
            self::MINIMUM_N => max(1, $total - $this->requiredApprovals($total, $value) + 1),
            self::PERCENTAGE => max(1, $total - $this->requiredApprovals($total, $value) + 1),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Semua Approver',
            self::ANY => 'Salah Satu',
            self::MINIMUM_N => 'Minimum N',
            self::PERCENTAGE => 'Persentase',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
