<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Jenis keanggotaan (Siswa, Guru, Pegawai, Wali, Alumni, Umum, VIP).
 *
 * Dikelola sebagai master (registry) sehingga CRUD, permission, dan
 * antarmukanya mengikuti pola master Fase 2. Kolom policy pinjam
 * (max_loan, loan_duration_days, renewal_limit, can_reserve) disimpan sebagai
 * REFERENSI konfigurasi — penerapannya di modul Circulation (Fase 4).
 */
class LibraryMembershipType extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = [
        'max_loan' => 'integer',
        'loan_duration_days' => 'integer',
        'renewal_limit' => 'integer',
        'can_reserve' => 'boolean',
        'sort_order' => 'integer',
    ];
}