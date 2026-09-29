<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Status daur hidup satu kegiatan supervisi. */
enum VisitStatus: string
{
    case DRAFT = 'DRAFT';
    case SCHEDULED = 'SCHEDULED';
    case PRE_CONFERENCE = 'PRE_CONFERENCE';
    case OBSERVED = 'OBSERVED';
    case ANALYZED = 'ANALYZED';
    case POST_CONFERENCE = 'POST_CONFERENCE';
    case ACTION_PLANNED = 'ACTION_PLANNED';
    case ACTION_IN_PROGRESS = 'ACTION_IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case EVALUATED = 'EVALUATED';
    case DISPUTED = 'DISPUTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SCHEDULED => 'Terjadwal',
            self::PRE_CONFERENCE => 'Pra-Observasi',
            self::OBSERVED => 'Sudah Diobservasi',
            self::ANALYZED => 'Dianalisis',
            self::POST_CONFERENCE => 'Pasca-Observasi',
            self::ACTION_PLANNED => 'RTL Disusun',
            self::ACTION_IN_PROGRESS => 'RTL Berjalan',
            self::COMPLETED => 'Selesai',
            self::EVALUATED => 'Dievaluasi',
            self::DISPUTED => 'Keberatan',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** Transisi yang diizinkan dari status ini. */
    public function allowedNext(): array
    {
        return match ($this) {
            self::DRAFT => [self::SCHEDULED, self::PRE_CONFERENCE, self::CANCELLED],
            self::SCHEDULED => [self::PRE_CONFERENCE, self::OBSERVED, self::CANCELLED],
            self::PRE_CONFERENCE => [self::OBSERVED, self::CANCELLED],
            self::OBSERVED => [self::ANALYZED, self::CANCELLED],
            self::ANALYZED => [self::POST_CONFERENCE],
            self::POST_CONFERENCE => [self::ACTION_PLANNED, self::COMPLETED],
            self::ACTION_PLANNED => [self::ACTION_IN_PROGRESS, self::COMPLETED],
            self::ACTION_IN_PROGRESS => [self::COMPLETED],
            self::COMPLETED => [self::EVALUATED, self::DISPUTED],
            self::EVALUATED => [self::DISPUTED],
            self::DISPUTED => [self::COMPLETED, self::ACTION_IN_PROGRESS, self::EVALUATED],
            self::CANCELLED => [],
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::EVALUATED, self::CANCELLED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
