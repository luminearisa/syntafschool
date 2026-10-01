<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumen kasus — metadata saja; berkasnya disimpan
`DocumentProviderInterface` (mis. disk lokal atau penyimpanan sekolah).

`visibility` mengikuti `ConfidentialityLevel`, sehingga dokumen rahasia tidak
dapat diunduh hanya karena pemanggil memiliki `bk.document.view`.
 *
 * Tabel: `bk_case_documents`
 */
class BkCaseDocument extends BkModel
{
    protected $table = 'bk_case_documents';

    use SoftDeletes;

    protected $casts = [
        'document_date' => 'date',
        'shared_with' => 'array',
        'is_sensitive' => 'boolean',
        'visibility' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
        'document_type' => \App\Modules\Bk\Domain\Enums\DocumentType::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BkParentMeeting::class, 'meeting_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
