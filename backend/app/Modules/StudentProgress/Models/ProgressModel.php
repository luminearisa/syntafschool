<?php

namespace App\Modules\StudentProgress\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model modul StudentProgress (Prestasi & Pelanggaran Siswa).
 *
 * Semua atribut mass assignable kecuali primary key, selaras dengan
 * `BaseModel` ERP dan `CbtModel` modul lain.
 */
abstract class ProgressModel extends Model
{
    protected $guarded = ['id'];
}
