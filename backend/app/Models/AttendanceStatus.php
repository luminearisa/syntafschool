<?php

namespace App\Models;

/**
 * Status presensi (Hadir, Alpa, Izin, Sakit, Terlambat).
 *
 * Kode disimpan sebagai konstanta agar aturan bisnis tidak menuliskan string
 * literal yang mudah salah ketik.
 */
class AttendanceStatus extends BaseModel
{
    public const CODE_PRESENT = 'H';
    public const CODE_ABSENT = 'A';
    public const CODE_PERMITTED = 'I';
    public const CODE_SICK = 'S';
    public const CODE_LATE = 'T';

    /** Status yang mewajibkan jenis izin (`leave_type_id`). */
    public const CODES_REQUIRING_LEAVE_TYPE = [
        self::CODE_PERMITTED,
        self::CODE_SICK,
    ];

    /** Status yang dianggap "hadir" saat menghitung persentase kehadiran. */
    public const CODES_COUNTED_AS_PRESENT = [
        self::CODE_PRESENT,
        self::CODE_LATE,
    ];
}
