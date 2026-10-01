<?php

namespace App\Modules\Bk\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model Modul Bimbingan & Konseling.
 *
 * Semua atribut mass-assignable kecuali primary key, mengikuti konvensi
 * `BaseModel` milik ERP sehingga penambahan kolom tidak perlu mengubah model.
 * Model anak menambahkan `SoftDeletes` pada catatan yang tidak boleh hilang
 * (kasus, sesi, catatan, dokumen) dan membiarkannya pada tabel teknis (audit,
 * notifikasi, pivot) agar jejaknya permanen.
 */
abstract class BkModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
