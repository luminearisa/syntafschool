<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Kalender akademik resmi (§4).
 *
 * Integrated → `academic_events`/hari efektif SIAKAD; Standalone → kosong
 * (LMS hanya menampilkan tenggat & kelas langsung miliknya sendiri).
 */
interface CalendarProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /**
     * Agenda akademik pada rentang tanggal.
     *
     * @return list<array{id:string,title:string,start_at:string,end_at:string|null,type:string|null,location:string|null,all_day:bool,source:string}>
     */
    public function eventsBetween(string $from, string $to, ?int $classId = null, ?int $academicYearId = null): array;
}
