<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model Modul Clinic / UKS.
 *
 * Semua atribut mass-assignable kecuali primary key, mengikuti konvensi
 * `BaseModel` milik ERP sehingga penambahan kolom tidak perlu mengubah model.
 * Model anak menambahkan `SoftDeletes` hanya pada tabel catatan klinis yang
 * tidak boleh hilang (kunjungan, resep, surat, dokumen, dsb).
 */
abstract class ClinicModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
