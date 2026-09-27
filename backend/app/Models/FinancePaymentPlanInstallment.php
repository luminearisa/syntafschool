<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu angsuran dari sebuah rencana pembayaran (§7).
 *
 * `paid_amount`/`outstanding_amount`/`status` adalah proyeksi: isinya dihitung
 * ulang dari pelunasan invoice oleh `FinancePaymentPlanService::sync()`, dan
 * kolomnya hanya menyimpan hasil hitungan itu supaya daftar & laporan murah.
 * Tidak ada jalur input yang boleh menulis ketiganya — menulisnya langsung
 * berarti membuat cicilan terlihat lunas tanpa satu rupiah pun masuk.
 *
 * Trait audit sengaja tidak dipakai di sini: barisnya berubah setiap kali ada
 * payment menyentuh invoice, sehingga jejak "siapa mengubah apa" sudah tinggal
 * di payment dan invoice-nya.
 */
class FinancePaymentPlanInstallment extends BaseModel
{
    protected $casts = [
        'payment_plan_id' => 'integer',
        'sequence' => 'integer',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FinancePaymentPlan::class, 'payment_plan_id');
    }

    public function isSettled(): bool
    {
        return (float) $this->outstanding_amount <= FinancePaymentPlan::EPSILON;
    }

    public function label(): string
    {
        return 'Angsuran '.$this->sequence;
    }

    public function statusLabel(): string
    {
        return FinancePaymentPlan::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
