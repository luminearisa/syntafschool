<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Format ekspor.
 *
 * Setiap format membawa konsekuensi berbeda: CSV dan XLSX paling mudah tersebar,
 * sehingga ekspor berisi data sensitif selalu melewati pemeriksaan izin, konfirmasi,
 * dan pencatatan audit.
 */
enum ExportFormat: string
{
    case CSV = 'CSV';
    case XLSX = 'XLSX';
    case PDF = 'PDF';
    case JSON = 'JSON';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CSV => 'CSV',
            self::XLSX => 'Excel (XLSX)',
            self::PDF => 'PDF',
            self::JSON => 'JSON',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::PDF => 'application/pdf',
            self::JSON => 'application/json',
        };
    }

    /** Format yang paling mudah tersebar sehingga perlu kehati-hatian ekstra. */
    public function spreadsEasily(): bool
    {
        return in_array($this, [self::CSV, self::XLSX, self::JSON], true);
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
