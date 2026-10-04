<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Support;

/**
 * Mesin status order laundry (§28).
 *
 * Transisi didefinisikan eksplisit sehingga setiap perpindahan status dapat
 * divalidasi, diaudit, dan dipetakan ke event domain. Status terminal
 * (COMPLETED/CANCELLED/LOST/DAMAGED) tidak bisa ditinggalkan.
 */
final class OrderStatus
{
    public const DRAFT = 'DRAFT';
    public const RECEIVED = 'RECEIVED';
    public const WEIGHED = 'WEIGHED';
    public const PAYMENT_REQUIRED = 'PAYMENT_REQUIRED';
    public const PAID = 'PAID';
    public const QUEUED = 'QUEUED';
    public const WASHING = 'WASHING';
    public const DRYING = 'DRYING';
    public const IRONING = 'IRONING';
    public const FOLDING = 'FOLDING';
    public const QUALITY_CHECK = 'QUALITY_CHECK';
    public const PACKAGED = 'PACKAGED';
    public const READY = 'READY';
    public const DELIVERED = 'DELIVERED';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const LOST = 'LOST';
    public const DAMAGED = 'DAMAGED';

    public const ALL = [
        self::DRAFT, self::RECEIVED, self::WEIGHED, self::PAYMENT_REQUIRED, self::PAID,
        self::QUEUED, self::WASHING, self::DRYING, self::IRONING, self::FOLDING,
        self::QUALITY_CHECK, self::PACKAGED, self::READY, self::DELIVERED, self::COMPLETED,
        self::CANCELLED, self::LOST, self::DAMAGED,
    ];

    public const TERMINAL = [self::COMPLETED, self::CANCELLED, self::LOST, self::DAMAGED];

    /** Urutan rantai pemrosesan setelah pembayaran. */
    public const PROCESSING_CHAIN = [
        self::QUEUED, self::WASHING, self::DRYING, self::IRONING, self::FOLDING,
        self::QUALITY_CHECK, self::PACKAGED, self::READY, self::DELIVERED, self::COMPLETED,
    ];

    /**
     * Peta transisi yang diizinkan. CANCELLED/LOST/DAMAGED ditambahkan
     * dinamis oleh `can()` untuk semua status non-terminal.
     */
    private const TRANSITIONS = [
        self::DRAFT => [self::RECEIVED, self::WEIGHED],
        self::RECEIVED => [self::WEIGHED],
        self::WEIGHED => [self::PAYMENT_REQUIRED, self::PAID],
        self::PAYMENT_REQUIRED => [self::PAID, self::PAYMENT_REQUIRED, self::QUEUED],
        self::PAID => [self::QUEUED],
        self::QUEUED => [self::WASHING],
        self::WASHING => [self::DRYING, self::WASHING],
        self::DRYING => [self::IRONING, self::DRYING],
        self::IRONING => [self::FOLDING, self::IRONING],
        self::FOLDING => [self::QUALITY_CHECK, self::FOLDING],
        self::QUALITY_CHECK => [self::PACKAGED, self::WASHING, self::IRONING, self::QUALITY_CHECK],
        self::PACKAGED => [self::READY],
        self::READY => [self::DELIVERED],
        self::DELIVERED => [self::COMPLETED],
        self::COMPLETED => [],
        self::CANCELLED => [],
        self::LOST => [],
        self::DAMAGED => [],
    ];

    public static function can(string $from, string $to): bool
    {
        if (! in_array($from, self::ALL, true) || ! in_array($to, self::ALL, true)) {
            return false;
        }

        if (in_array($from, self::TERMINAL, true)) {
            return false;
        }

        // Exception path: order belum selesai bisa dibatalkan/dinyatakan hilang/rusak
        // (LOST/DAMAGED wajib disertai incident — ditegakkan di service).
        if (in_array($to, [self::CANCELLED, self::LOST, self::DAMAGED], true)) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Status berikutnya setelah satu tahap batch selesai (§33). */
    public static function nextAfterStage(string $stage): ?string
    {
        return match ($stage) {
            self::WASHING => self::DRYING,
            self::DRYING => self::IRONING,
            self::IRONING => self::FOLDING,
            self::FOLDING => self::QUALITY_CHECK,
            default => null,
        };
    }

    /** Status order saat batch satu tahap dimulai. */
    public static function stageStatus(string $stage): ?string
    {
        return in_array($stage, [self::WASHING, self::DRYING, self::IRONING, self::FOLDING], true)
            ? $stage
            : null;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** Kelompok UI (§28): INTAKE/PAYMENT/PROCESSING/HANDOVER/EXCEPTION. */
    public static function group(string $status): string
    {
        foreach ((array) config('laundry.status_groups', []) as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return (string) $group;
            }
        }

        return 'PROCESSING';
    }
}
