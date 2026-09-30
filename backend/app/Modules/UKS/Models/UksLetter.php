<?php

namespace App\Modules\UKS\Models;

use App\Models\BaseModel;
use App\Models\Student;
use App\Models\User;
use App\Modules\UKS\Domain\Enums\LetterType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UksLetter extends BaseModel
{
    protected $table = 'uks_letters';

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'letter_type' => LetterType::class,
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(UksVisit::class, 'visit_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
