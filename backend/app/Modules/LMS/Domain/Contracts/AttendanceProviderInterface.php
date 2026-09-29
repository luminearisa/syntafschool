<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Kehadiran akademik resmi (§9).
 *
 * Tiga jenis kehadiran sengaja **tidak dicampur**:
 *   - Academic Attendance  → `student_attendances` SIAKAD (kontrak ini),
 *   - LMS Attendance       → penyelesaian aktivitas & kunjungan belajar,
 *   - Live Session Attendance → kehadiran kelas langsung (Phase 4).
 *
 * Kontrak ini hanya membaca fakta akademik; LMS tidak pernah menulisi tabel
 * kehadiran akademik agar integritas data resmi terjaga (§9, §51).
 */
interface AttendanceProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /**
     * Rekap kehadiran akademik seorang siswa pada rentang tanggal.
     *
     * @return array{records:int,present:int,absent:int,late:int,excused:int,rate:float|null,by_status:array<string,int>,source:string}
     */
    public function academicSummary(int $studentId, ?string $from = null, ?string $to = null): array;

    /**
     * Kehadiran akademik per hari (untuk kalender/portal wali).
     *
     * @return list<array{date:string,status:string,label:string,subject:string|null,note:string|null}>
     */
    public function academicRecords(int $studentId, ?string $from = null, ?string $to = null, int $limit = 30): array;

    /**
     * Apakah siswa terdaftar sebagai santri/boarding? Dipakai bilah konteks
     * portal wali dan guru (bukan untuk membatasi akses belajar).
     */
    public function isBoardingStudent(int $studentId): bool;
}
