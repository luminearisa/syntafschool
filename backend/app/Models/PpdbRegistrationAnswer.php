<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawaban pendaftaran: menampung jawaban field form (field_key) maupun
 * pertanyaan tambahan (question_id) secara seragam.
 */
class PpdbRegistrationAnswer extends BaseModel
{
    protected $casts = ['value_json' => 'array'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistration::class, 'registration_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PpdbQuestion::class, 'question_id');
    }

    /** Nilai jawaban dalam bentuk aslinya (skalar atau larik). */
    public function typedValue(): mixed
    {
        return $this->value_json ?? $this->value;
    }
}