<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status kasus \u2014 lifecycle operasional BK.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum CaseStatus: string
{
    case OPEN = 'OPEN';
    case ASSESSMENT = 'ASSESSMENT';
    case INTERVENTION = 'INTERVENTION';
    case MONITORING = 'MONITORING';
    case ON_HOLD = 'ON_HOLD';
    case REFERRED = 'REFERRED';
    case RESOLVED = 'RESOLVED';
    case CLOSED = 'CLOSED';
    case REOPENED = 'REOPENED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Terbuka',
            self::ASSESSMENT => 'Asesmen',
            self::INTERVENTION => 'Intervensi',
            self::MONITORING => 'Pemantauan',
            self::ON_HOLD => 'Ditahan',
            self::REFERRED => 'Diteruskan',
            self::RESOLVED => 'Tuntas',
            self::CLOSED => 'Ditutup',
            self::REOPENED => 'Dibuka kembali',
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

    /** Kasus yang masih berjalan (belum ditutup). */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::CLOSED, self::RESOLVED], true);
    }

    /** Status akhir \u2014 perubahan setelah ini menuntut pembukaan kembali. */
    public function isFinal(): bool
    {
        return in_array($this, [self::CLOSED], true);
    }

    /** Status yang boleh dipilih dari status saat ini (tanpa lompatan liar). */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::ASSESSMENT, self::INTERVENTION, self::ON_HOLD, self::REFERRED, self::CLOSED],
            self::ASSESSMENT => [self::INTERVENTION, self::MONITORING, self::ON_HOLD, self::REFERRED, self::RESOLVED, self::CLOSED],
            self::INTERVENTION => [self::MONITORING, self::ON_HOLD, self::REFERRED, self::RESOLVED, self::CLOSED],
            self::MONITORING => [self::INTERVENTION, self::ON_HOLD, self::RESOLVED, self::CLOSED],
            self::ON_HOLD => [self::ASSESSMENT, self::INTERVENTION, self::MONITORING, self::CLOSED],
            self::REFERRED => [self::MONITORING, self::RESOLVED, self::CLOSED, self::REOPENED],
            self::RESOLVED => [self::CLOSED, self::REOPENED, self::MONITORING],
            self::CLOSED => [self::REOPENED],
            self::REOPENED => [self::ASSESSMENT, self::INTERVENTION, self::MONITORING, self::CLOSED],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->allowedTransitions(), true);
    }
}
