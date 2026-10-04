<?php

namespace App\Modules\Mutation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit perpindahan status sebuah permohonan mutasi.
 */
class StudentTransferHistory extends MutationModel
{
    protected $table = 'student_transfer_histories';

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StudentTransfer::class, 'student_transfer_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
