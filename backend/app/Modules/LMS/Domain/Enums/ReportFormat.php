<?php

namespace App\Modules\LMS\Domain\Enums;

/** Format ekspor laporan (§22). */
enum ReportFormat: string
{
    case CSV = 'CSV';
    case XLSX = 'XLSX';
    case PDF = 'PDF';

    public function label(): string
    {
        return $this->value;
    }

    public function mime(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::PDF => 'application/pdf',
        };
    }

    /** CSV selalu tersedia; XLSX/PDF memerlukan pustaka tambahan. */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::CSV => true,
            self::XLSX, self::PDF => config('lms.reports.allow_rich_formats', false) === true,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
