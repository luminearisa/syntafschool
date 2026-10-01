<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis sesi konseling.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum SessionType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case GROUP = 'GROUP';
    case PARENT = 'PARENT';
    case STUDENT_PARENT = 'STUDENT_PARENT';
    case TEACHER = 'TEACHER';
    case CASE_CONFERENCE = 'CASE_CONFERENCE';
    case FOLLOW_UP = 'FOLLOW_UP';
    case EMERGENCY = 'EMERGENCY';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Individual',
            self::GROUP => 'Kelompok',
            self::PARENT => 'Orang tua',
            self::STUDENT_PARENT => 'Siswa & orang tua',
            self::TEACHER => 'Guru',
            self::CASE_CONFERENCE => 'Konferensi kasus',
            self::FOLLOW_UP => 'Tindak lanjut',
            self::EMERGENCY => 'Darurat',
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
