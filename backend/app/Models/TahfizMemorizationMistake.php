<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detail satu kesalahan dalam setoran hafalan.
 *
 * Bukan hanya menyimpan skor akhir: setiap kesalahan diberi tipe (lupa ayat,
 * salah sambung, salah lafadz, tajwid, makhraj, harakat, waqaf, mad, ghunnah),
 * jumlah kemunculan, ayat, dan catatan. Inilah yang memungkinkan analitik
 * pola kesalahan peserta.
 */
class TahfizMemorizationMistake extends BaseModel
{
    protected $casts = [
        'count' => 'integer',
    ];

    public function memorizationRecord(): BelongsTo
    {
        return $this->belongsTo(TahfizMemorizationRecord::class, 'memorization_record_id');
    }

    public function mistakeType(): BelongsTo
    {
        return $this->belongsTo(TahfizMistakeType::class, 'mistake_type_id');
    }
}
