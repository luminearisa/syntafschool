<?php

namespace App\Models;

class DiscountType extends BaseModel
{
    protected $casts = ['value' => 'decimal:2', 'is_active' => 'boolean'];
}
