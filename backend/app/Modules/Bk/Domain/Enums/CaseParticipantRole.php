<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Peran pihak yang terlibat dalam kasus.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum CaseParticipantRole: string
{
    case COUNSELOR = 'COUNSELOR';
    case CO_COUNSELOR = 'CO_COUNSELOR';
    case COORDINATOR = 'COORDINATOR';
    case HOMEROOM_TEACHER = 'HOMEROOM_TEACHER';
    case TEACHER = 'TEACHER';
    case BOARDING_STAFF = 'BOARDING_STAFF';
    case PARENT = 'PARENT';
    case STUDENT = 'STUDENT';
    case CLINIC = 'CLINIC';
    case ACADEMIC = 'ACADEMIC';
    case DISCIPLINE = 'DISCIPLINE';
    case MANAGEMENT = 'MANAGEMENT';
    case EXTERNAL = 'EXTERNAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::COUNSELOR => 'Konselor',
            self::CO_COUNSELOR => 'Konselor pendamping',
            self::COORDINATOR => 'Koordinator BK',
            self::HOMEROOM_TEACHER => 'Wali kelas',
            self::TEACHER => 'Guru',
            self::BOARDING_STAFF => 'Pengasuh asrama',
            self::PARENT => 'Orang tua/wali',
            self::STUDENT => 'Siswa',
            self::CLINIC => 'Klinik/UKS',
            self::ACADEMIC => 'Akademik',
            self::DISCIPLINE => 'Kedisiplinan',
            self::MANAGEMENT => 'Manajemen',
            self::EXTERNAL => 'Pihak luar',
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
