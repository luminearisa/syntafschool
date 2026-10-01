<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model Modul Student Activity & Extracurricular.
 *
 * Seluruh atribut boleh diisi massal kecuali primary key — konvensi yang sama
 * dengan modul lain di ERP ini, sehingga penambahan kolom tidak menuntut
 * perubahan model. Model anak menambahkan `SoftDeletes` hanya pada catatan yang
 * tidak boleh hilang (program, keanggotaan, prestasi, sertifikat).
 */
abstract class ActivityModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
