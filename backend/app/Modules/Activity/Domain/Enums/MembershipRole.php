<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum MembershipRole — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum MembershipRole: string
{
    case MEMBER = 'MEMBER';
    case CAPTAIN = 'CAPTAIN';
    case VICE_CAPTAIN = 'VICE_CAPTAIN';
    case SECRETARY = 'SECRETARY';
    case TREASURER = 'TREASURER';
    case COMMITTEE = 'COMMITTEE';
    case TEAM_LEADER = 'TEAM_LEADER';
    case MENTOR = 'MENTOR';
    case PEER_MENTOR = 'PEER_MENTOR';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::MEMBER => 'Anggota',
            self::CAPTAIN => 'Ketua',
            self::VICE_CAPTAIN => 'Wakil Ketua',
            self::SECRETARY => 'Sekretaris',
            self::TREASURER => 'Bendahara',
            self::COMMITTEE => 'Pengurus',
            self::TEAM_LEADER => 'Pemimpin Tim',
            self::MENTOR => 'Pembimbing Sebaya',
            self::PEER_MENTOR => 'Mentor Sebaya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
