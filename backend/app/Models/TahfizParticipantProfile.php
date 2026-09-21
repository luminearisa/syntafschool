<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribut pendaftaran peserta: pengalaman menghafal, hafalan terakhir,
 * kemampuan membaca, target hafalan, dan preferensi jadwal.
 *
 * Dipisah dari identitas peserta agar data pendaftaran bisa berubah tanpa
 * mengubah identitas.
 */
class TahfizParticipantProfile extends BaseModel
{
    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }
}
