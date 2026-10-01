<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Objek yang dinilai pada umpan balik.
 *
 * Umpan balik menempel pada layanan yang benar-benar diterima siswa/wali, bukan
 * pada modul secara umum, supaya hasilnya dapat ditindaklanjuti.
 */
enum FeedbackSubjectType: string
{
    case SESSION = 'SESSION';
    case INTERVENTION = 'INTERVENTION';
    case PROGRAM = 'PROGRAM';
    case CASE_CLOSURE = 'CASE_CLOSURE';
    case COUNSELING_SERVICE = 'COUNSELING_SERVICE';
    case PARENT_MEETING = 'PARENT_MEETING';
    case FOLLOW_UP = 'FOLLOW_UP';
    case EXTERNAL_REFERRAL = 'EXTERNAL_REFERRAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SESSION => 'Sesi konseling',
            self::INTERVENTION => 'Intervensi',
            self::PROGRAM => 'Program',
            self::CASE_CLOSURE => 'Penutupan kasus',
            self::COUNSELING_SERVICE => 'Layanan konseling',
            self::PARENT_MEETING => 'Pertemuan orang tua',
            self::FOLLOW_UP => 'Tindak lanjut',
            self::EXTERNAL_REFERRAL => 'Rujukan eksternal',
        };
    }

    /** Audiens yang lazim memberi umpan balik untuk objek ini. */
    public function defaultAudience(): string
    {
        return match ($this) {
            self::PARENT_MEETING, self::EXTERNAL_REFERRAL => 'PARENT',
            default => 'STUDENT',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
