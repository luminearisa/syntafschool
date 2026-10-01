<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Aksi yang dicatat pada jejak audit BK \u2014 append-only.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AuditAction: string
{
    case VIEW = 'VIEW';
    case VIEW_CONFIDENTIAL = 'VIEW_CONFIDENTIAL';
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case ASSIGN = 'ASSIGN';
    case REASSIGN = 'REASSIGN';
    case SCREEN = 'SCREEN';
    case SESSION_RECORD = 'SESSION_RECORD';
    case NOTE_ADD = 'NOTE_ADD';
    case EXPORT = 'EXPORT';
    case SHARE = 'SHARE';
    case CLOSE = 'CLOSE';
    case REOPEN = 'REOPEN';
    case ESCALATE = 'ESCALATE';
    case DOCUMENT_ADD = 'DOCUMENT_ADD';
    case DOCUMENT_VIEW = 'DOCUMENT_VIEW';
    case CONSENT_DECIDE = 'CONSENT_DECIDE';
    case REQUEST_SUBMIT = 'REQUEST_SUBMIT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::VIEW => 'Melihat',
            self::VIEW_CONFIDENTIAL => 'Melihat data rahasia',
            self::CREATE => 'Membuat',
            self::UPDATE => 'Mengubah',
            self::DELETE => 'Menghapus',
            self::ASSIGN => 'Menugaskan',
            self::REASSIGN => 'Mengganti penugasan',
            self::SCREEN => 'Screening',
            self::SESSION_RECORD => 'Mencatat sesi',
            self::NOTE_ADD => 'Menambah catatan',
            self::EXPORT => 'Ekspor',
            self::SHARE => 'Berbagi',
            self::CLOSE => 'Menutup kasus',
            self::REOPEN => 'Membuka kembali',
            self::ESCALATE => 'Eskalasi',
            self::DOCUMENT_ADD => 'Menambah dokumen',
            self::DOCUMENT_VIEW => 'Melihat dokumen',
            self::CONSENT_DECIDE => 'Memutuskan persetujuan',
            self::REQUEST_SUBMIT => 'Mengajukan permintaan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
