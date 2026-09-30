<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Model;

abstract class CbtModel extends Model
{
    protected $guarded = ['id'];
}
