<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalkulator periode jatah laundry (§7).
 *
 * Periode ditentukan deterministik dari tanggal: kunci periode (period_key)
 * stabil sehingga entitlement baru selalu bisa dicari/dibuat idempoten.
 * SEMESTER dan ACADEMIC_YEAR dibaca dari kalender akademik bila tersedia,
 * dengan fallback deterministik agar modul tetap jalan tanpa data akademik.
 */
final class LaundryPeriod
{
    public const DAILY = 'DAILY';
    public const WEEKLY = 'WEEKLY';
    public const MONTHLY = 'MONTHLY';
    public const SEMESTER = 'SEMESTER';
    public const ACADEMIC_YEAR = 'ACADEMIC_YEAR';
    public const CUSTOM = 'CUSTOM';

    /**
     * Hitung kunci dan rentang periode.
     *
     * @param  array{start?: string|null, end?: string|null}  $custom  rentang utk CUSTOM
     * @return array{key: string, start: string, end: string, type: string}
     */
    public static function resolve(string $type, CarbonImmutable $date, array $custom = []): array
    {
        return match ($type) {
            self::DAILY => [
                'type' => $type,
                'key' => $date->format('Y-m-d'),
                'start' => $date->startOfDay()->toDateString(),
                'end' => $date->endOfDay()->toDateString(),
            ],
            self::WEEKLY => [
                'type' => $type,
                'key' => sprintf('%d-W%02d', $date->isoWeekYear(), $date->isoWeek()),
                'start' => $date->startOfWeek()->toDateString(),
                'end' => $date->endOfWeek()->toDateString(),
            ],
            self::SEMESTER => self::semester($date),
            self::ACADEMIC_YEAR => self::academicYear($date),
            self::CUSTOM => self::custom($date, $custom),
            default => [
                'type' => self::MONTHLY,
                'key' => $date->format('Y-m'),
                'start' => $date->startOfMonth()->toDateString(),
                'end' => $date->endOfMonth()->toDateString(),
            ],
        };
    }

    /** Periode sebelumnya (untuk carry over §8). */
    public static function previous(string $type, CarbonImmutable $date, array $custom = []): array
    {
        if ($type === self::CUSTOM) {
            $start = isset($custom['start']) ? CarbonImmutable::parse($custom['start']) : $date->startOfMonth();

            return self::resolve($type, $start->subDay(), $custom);
        }

        $shifted = match ($type) {
            self::DAILY => $date->subDay(),
            self::WEEKLY => $date->subWeek(),
            self::SEMESTER => self::semesterStart($date)?->subDay() ?? $date->subMonthsNoOverflow(6),
            self::ACADEMIC_YEAR => self::academicYearStart($date)?->subDay() ?? $date->subYear(),
            default => $date->subMonthNoOverflow(),
        };

        return self::resolve($type, $shifted, $custom);
    }

    private static function semester(CarbonImmutable $date): array
    {
        $row = self::calendarRow('semesters', $date);

        if ($row !== null) {
            return [
                'type' => self::SEMESTER,
                'key' => 'SEM-'.$row->id,
                'start' => CarbonImmutable::parse($row->start_date)->toDateString(),
                'end' => CarbonImmutable::parse($row->end_date)->toDateString(),
            ];
        }

        // Fallback deterministik: semester kalender (Jan–Jun / Jul–Des).
        $firstHalf = $date->month <= 6;

        return [
            'type' => self::SEMESTER,
            'key' => sprintf('SEM-%d-%s', $date->year, $firstHalf ? 'A' : 'B'),
            'start' => ($firstHalf ? $date->setMonth(1) : $date->setMonth(7))->startOfMonth()->toDateString(),
            'end' => ($firstHalf ? $date->setMonth(6) : $date->setMonth(12))->endOfMonth()->toDateString(),
        ];
    }

    private static function academicYear(CarbonImmutable $date): array
    {
        $row = self::calendarRow('academic_years', $date);

        if ($row !== null) {
            return [
                'type' => self::ACADEMIC_YEAR,
                'key' => 'AY-'.$row->id,
                'start' => CarbonImmutable::parse($row->start_date)->toDateString(),
                'end' => CarbonImmutable::parse($row->end_date)->toDateString(),
            ];
        }

        return [
            'type' => self::ACADEMIC_YEAR,
            'key' => 'AY-'.$date->year,
            'start' => $date->startOfYear()->toDateString(),
            'end' => $date->endOfYear()->toDateString(),
        ];
    }

    private static function custom(CarbonImmutable $date, array $custom): array
    {
        $start = isset($custom['start']) ? CarbonImmutable::parse($custom['start']) : $date->startOfMonth();
        $end = isset($custom['end']) ? CarbonImmutable::parse($custom['end']) : $date->endOfMonth();

        return [
            'type' => self::CUSTOM,
            'key' => 'CUS-'.$start->format('Ymd').'-'.$end->format('Ymd'),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }

    private static function calendarRow(string $table, CarbonImmutable $date): ?object
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }

            return DB::table($table)
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->orderBy('id')
                ->first(['id', 'start_date', 'end_date']);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function semesterStart(CarbonImmutable $date): ?CarbonImmutable
    {
        $row = self::calendarRow('semesters', $date);

        return $row !== null ? CarbonImmutable::parse($row->start_date) : null;
    }

    private static function academicYearStart(CarbonImmutable $date): ?CarbonImmutable
    {
        $row = self::calendarRow('academic_years', $date);

        return $row !== null ? CarbonImmutable::parse($row->start_date) : null;
    }
}
