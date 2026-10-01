<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model Modul Workflow.
 *
 * Seluruh atribut mass-assignable kecuali primary key, mengikuti konvensi
 * model ERP lainnya sehingga penambahan kolom tidak menuntut perubahan model.
 * Tabel riwayat (keputusan, event, audit, submission, konteks) mematikan
 * `$timestamps` dan hanya menyimpan `created_at` — bukti bahwa riwayat memang
 * tidak dirancang untuk ditulis ulang (§47).
 */
abstract class WorkflowModel extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
