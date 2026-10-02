<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Support;

/**
 * Siklus hidup event (§9) dan aturan perpindahannya.
 *
 * Aturan transisi diletakkan di konfigurasi (`events.transitions`) agar
 * institusi dapat menyesuaikan alur tanpa mengubah kode, dan agar setiap
 * perpindahan status wajib melewati satu pintu: {@see self::canTransition()}.
 */
final class EventStatus
{
    public const DRAFT = 'DRAFT';
    public const PROPOSED = 'PROPOSED';
    public const UNDER_REVIEW = 'UNDER_REVIEW';
    public const APPROVED = 'APPROVED';
    public const PLANNING = 'PLANNING';
    public const REGISTRATION_OPEN = 'REGISTRATION_OPEN';
    public const REGISTRATION_CLOSED = 'REGISTRATION_CLOSED';
    public const READY = 'READY';
    public const ONGOING = 'ONGOING';
    public const COMPLETED = 'COMPLETED';
    public const EVALUATION = 'EVALUATION';
    public const CLOSED = 'CLOSED';
    public const CANCELLED = 'CANCELLED';
    public const ARCHIVED = 'ARCHIVED';

    /** Semua status yang dikenal, berurutan sesuai siklus hidup. */
    public static function all(): array
    {
        return [
            self::DRAFT, self::PROPOSED, self::UNDER_REVIEW, self::APPROVED,
            self::PLANNING, self::REGISTRATION_OPEN, self::REGISTRATION_CLOSED,
            self::READY, self::ONGOING, self::COMPLETED, self::EVALUATION,
            self::CLOSED, self::CANCELLED, self::ARCHIVED,
        ];
    }

    /** Status yang menandakan event tidak lagi dapat diubah bebas. */
    public static function immutableish(): array
    {
        return [self::COMPLETED, self::EVALUATION, self::CLOSED, self::CANCELLED, self::ARCHIVED];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(strtoupper($status), [self::CLOSED, self::CANCELLED, self::ARCHIVED], true);
    }

    /** Status yang menandakan event sudah disetujui sehingga berversi (§93). */
    public static function isVersioned(string $status): bool
    {
        return ! in_array(strtoupper($status), [self::DRAFT, self::PROPOSED, self::UNDER_REVIEW], true);
    }

    /** Status penerimaan pendaftaran (§30). */
    public static function registrationStates(): array
    {
        return [
            'PENDING', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED',
            'WAITLIST', 'REJECTED', 'CANCELLED', 'ATTENDED', 'NO_SHOW',
        ];
    }

    /** Status kehadiran peserta (§38). */
    public static function attendanceStates(): array
    {
        return ['REGISTERED', 'CHECKED_IN', 'CHECKED_OUT', 'NO_SHOW'];
    }

    /** Status undangan (§26). */
    public static function invitationStates(): array
    {
        return ['DRAFT', 'SENT', 'DELIVERED', 'OPENED', 'CONFIRMED', 'DECLINED'];
    }

    /** Status tamu (§25). */
    public static function guestStates(): array
    {
        return ['INVITED', 'CONFIRMED', 'DECLINED', 'ARRIVED', 'DEPARTED', 'NO_SHOW'];
    }

    /** Status tugas panitia (§42). */
    public static function taskStates(): array
    {
        return ['TODO', 'IN_PROGRESS', 'BLOCKED', 'DONE', 'CANCELLED'];
    }

    /** Status pemeriksaan venue (§19). */
    public static function venueStates(): array
    {
        return ['AVAILABLE', 'CONFLICT', 'MAINTENANCE', 'BLOCKED'];
    }

    /** Tingkat risiko (§60). */
    public static function riskLevels(): array
    {
        return ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    }

    /** Tingkat keparahan insiden (§61). */
    public static function severityLevels(): array
    {
        return ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    }

    /** Skor numerik untuk sebuah tingkat, dipakai pemeringkatan risiko/insiden. */
    public static function weight(string $level): int
    {
        return match (strtoupper($level)) {
            'LOW' => 1,
            'MEDIUM' => 2,
            'HIGH' => 3,
            'CRITICAL' => 4,
            default => 0,
        };
    }

    // ── Aturan perpindahan ──────────────────────────────────────────────────

    /**
     * Apakah sebuah perpindahan status diizinkan.
     *
     * Aturan diambil dari `events.transitions`. Bila status tujuan tidak
     * tercantum, perpindahan ditolak — termasuk perpindahan ke status yang
     * sama, agar pencatatan riwayat tidak menipu.
     */
    public static function canTransition(string $from, string $to): bool
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return false;
        }

        $allowed = (array) config("events.transitions.{$from}", []);

        return in_array($to, array_map('strtoupper', $allowed), true);
    }

    /** Aturan perpindahan lengkap yang berlaku, untuk ditampilkan di API meta. */
    public static function transitionMap(): array
    {
        return (array) config('events.transitions', []);
    }

    /**
     * Memvalidasi perpindahan dan melempar bila tidak sah.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertTransition(string $from, string $to, string $context = ''): void
    {
        if (! self::canTransition($from, $to)) {
            $suffix = $context !== '' ? " ({$context})" : '';

            throw new \InvalidArgumentException(
                "Perpindahan status {$from} → {$to} tidak diizinkan{$suffix}."
            );
        }
    }

    /** Label bahasa manusia untuk sebuah status. */
    public static function label(string $status): string
    {
        return (string) config('events.status_labels.'.strtoupper($status), $status);
    }
}
