<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\DocumentType;
use App\Modules\Clinic\Domain\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumen kesehatan ber-versi pada disk privat dengan akses terkontrol.
 *
 * Tabel: `clinic_documents`
 */
class ClinicDocument extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_documents';

    use SoftDeletes;

    protected $casts = [
        'version' => 'integer',
        'is_current' => 'boolean',
        'is_signed' => 'boolean',
        'size_bytes' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(ClinicDocument::class, 'replaces_id');
    }
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeEnum(): DocumentType
    {
        return DocumentType::from($this->type);
    }

    public function visibilityEnum(): DocumentVisibility
    {
        return DocumentVisibility::from($this->visibility);
    }
}
