<?php

namespace App\Modules\Mutation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model modul Mutation: semua atribut mass assignable kecuali primary key.
 */
abstract class MutationModel extends Model
{
    protected $guarded = ['id'];
}
