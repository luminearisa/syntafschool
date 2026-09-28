<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jawaban polling (§38). */
class LmsChoiceResponse extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_choice_responses';

    public function choice(): BelongsTo
    {
        return $this->belongsTo(LmsChoice::class, 'choice_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(LmsChoiceOption::class, 'option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }
}
