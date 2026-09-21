<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu field pada form pendaftaran (form builder).
 *
 * Field adalah DATA: admin menentukan label, jenis, section, urutan, validasi,
 * dan apakah wajib — tidak ada field yang ditulis keras di kode.
 */
class PpdbFormField extends BaseModel
{
    use LogsPpdbActivity;

    public const TYPES = [
        'text', 'textarea', 'number', 'date', 'email', 'phone',
        'select', 'radio', 'checkbox', 'multi_select', 'file',
    ];

    public const SECTIONS = [
        'data_calon', 'data_orang_tua', 'data_wali', 'alamat',
        'sekolah_asal', 'kesehatan', 'tambahan',
    ];

    /** Label section untuk UI (satu sumber kebenaran, tidak di-hardcode frontend). */
    public const SECTION_LABELS = [
        'data_calon' => 'Data Calon',
        'data_orang_tua' => 'Data Orang Tua',
        'data_wali' => 'Data Wali',
        'alamat' => 'Alamat',
        'sekolah_asal' => 'Sekolah Asal',
        'kesehatan' => 'Data Kesehatan',
        'tambahan' => 'Data Tambahan',
    ];

    protected $casts = [
        'required' => 'boolean',
        'active' => 'boolean',
        'order' => 'integer',
        'options' => 'array',
        'validation' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_FORM_FIELD';
    }
}