<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis catatan kasus.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum NoteType: string
{
    case GENERAL = 'GENERAL';
    case COUNSELOR_NOTE = 'COUNSELOR_NOTE';
    case SENSITIVE = 'SENSITIVE';
    case OBSERVATION = 'OBSERVATION';
    case CONTACT = 'CONTACT';
    case AMENDMENT = 'AMENDMENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Catatan umum',
            self::COUNSELOR_NOTE => 'Catatan konselor',
            self::SENSITIVE => 'Catatan sensitif',
            self::OBSERVATION => 'Observasi',
            self::CONTACT => 'Catatan kontak',
            self::AMENDMENT => 'Perbaikan catatan',
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
