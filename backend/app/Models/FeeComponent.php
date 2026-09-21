<?php

namespace App\Models;

class FeeComponent extends BaseModel
{
    protected $casts = ['default_amount' => 'decimal:2', 'is_active' => 'boolean'];
}
