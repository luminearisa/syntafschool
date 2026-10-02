<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorizedPickupPerson extends Model
{

    protected $table = 'authorized_pickup_persons';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'student_id' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'created_by' => 'integer',
    ];

    public function dropoffs(): HasMany
    {
        return $this->hasMany(DropoffEvent::class, 'authorized_pickup_person_id');
    }
}
