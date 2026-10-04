<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model Modul Procurement & Vendor.
 *
 * Semua atribut mass-assignable kecuali primary key, mengikuti konvensi
 * `BaseModel` milik ERP sehingga penambahan kolom tidak perlu mengubah model.
 */
abstract class ProcurementModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
