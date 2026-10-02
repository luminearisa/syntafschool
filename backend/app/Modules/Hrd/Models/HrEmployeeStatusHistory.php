<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat status kepegawaian (§18, §75).
 *
 * Setiap perpindahan status meninggalkan baris di sini — termasuk perpindahan
 * yang dihasilkan transaksi. Laporan "berapa pegawai berhenti tahun ini?" karena
 * itu membaca riwayat, bukan menebak dari tanggal.
 */
class HrEmployeeStatusHistory extends HrdModel
{
    protected $casts = ['effective_date' => 'date'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'transaction_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
