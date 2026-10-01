<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Tingkat kerahasiaan \u2014 inti privasi Modul BK.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum ConfidentialityLevel: string
{
    case PUBLIC_INTERNAL = 'PUBLIC_INTERNAL';
    case STAFF_ONLY = 'STAFF_ONLY';
    case COUNSELOR_ONLY = 'COUNSELOR_ONLY';
    case RESTRICTED = 'RESTRICTED';
    case CONFIDENTIAL = 'CONFIDENTIAL';
    case HIGHLY_CONFIDENTIAL = 'HIGHLY_CONFIDENTIAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PUBLIC_INTERNAL => 'Internal umum',
            self::STAFF_ONLY => 'Khusus staf',
            self::COUNSELOR_ONLY => 'Khusus konselor',
            self::RESTRICTED => 'Terbatas',
            self::CONFIDENTIAL => 'Rahasia',
            self::HIGHLY_CONFIDENTIAL => 'Sangat rahasia',
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

    /**
     * Peringkat kerahasiaan \u2014 makin besar makin ketat.
     *
     * Dipakai `BkAccessService` untuk memutuskan apakah pemegang izin
     * `bk.confidential.view` boleh membuka baris, atau hanya konselor yang
     * menangani kasus tersebut.
     */
    public function rank(): int
    {
        return match ($this) {
            self::PUBLIC_INTERNAL => 1,
            self::STAFF_ONLY => 2,
            self::RESTRICTED => 3,
            self::COUNSELOR_ONLY => 4,
            self::CONFIDENTIAL => 5,
            self::HIGHLY_CONFIDENTIAL => 6,
        };
    }

    /** Hanya konselor penanggung jawab (dan supervisor) yang boleh membuka. */
    public function isCounselorOnly(): bool
    {
        return $this->rank() >= self::COUNSELOR_ONLY->rank();
    }

    /** Tidak boleh muncul di daftar umum, notifikasi, log aplikasi, atau ekspor massal. */
    public function isSecret(): bool
    {
        return $this->rank() >= self::CONFIDENTIAL->rank();
    }

    /** Boleh dibagikan kepada wali kelas / guru / pengasuh asrama. */
    public function isShareableWithStaff(): bool
    {
        return $this->rank() <= self::STAFF_ONLY->rank();
    }

    /** Level tertinggi di antara dua level \u2014 dipakai saat menggabungkan data. */
    public static function strictest(self ...$levels): self
    {
        $result = self::PUBLIC_INTERNAL;

        foreach ($levels as $level) {
            if ($level->rank() > $result->rank()) {
                $result = $level;
            }
        }

        return $result;
    }

    /** Level yang boleh diakses pemegang izin umum modul BK. */
    public static function staffVisible(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->rank() <= self::STAFF_ONLY->rank(),
        ));
    }
}
