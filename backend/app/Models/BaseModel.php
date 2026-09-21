<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model untuk seluruh model aplikasi.
 */
abstract class BaseModel extends Model
{
    use HasFactory;

    /**
     * Semua atribut mass assignable kecuali primary key.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];
}
