<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Kategori kekhawatiran pada referral.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum ReferralCategory: string
{
    case ACADEMIC = 'ACADEMIC';
    case BEHAVIORAL = 'BEHAVIORAL';
    case SOCIAL = 'SOCIAL';
    case EMOTIONAL = 'EMOTIONAL';
    case FAMILY = 'FAMILY';
    case ATTENDANCE = 'ATTENDANCE';
    case DISCIPLINE = 'DISCIPLINE';
    case BULLYING = 'BULLYING';
    case PEER_CONFLICT = 'PEER_CONFLICT';
    case CAREER = 'CAREER';
    case BOARDING = 'BOARDING';
    case HEALTH = 'HEALTH';
    case SAFEGUARDING = 'SAFEGUARDING';
    case CRISIS = 'CRISIS';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ACADEMIC => 'Akademik',
            self::BEHAVIORAL => 'Perilaku',
            self::SOCIAL => 'Sosial',
            self::EMOTIONAL => 'Emosi',
            self::FAMILY => 'Keluarga',
            self::ATTENDANCE => 'Kehadiran',
            self::DISCIPLINE => 'Kedisiplinan',
            self::BULLYING => 'Perundungan',
            self::PEER_CONFLICT => 'Konflik teman',
            self::CAREER => 'Karier',
            self::BOARDING => 'Asrama',
            self::HEALTH => 'Kesehatan',
            self::SAFEGUARDING => 'Perlindungan anak',
            self::CRISIS => 'Krisis',
            self::OTHER => 'Lain-lain',
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
