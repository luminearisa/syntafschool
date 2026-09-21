<?php

namespace App\Models;

class PaymentMethod extends BaseModel
{
    protected $casts = ['is_active' => 'boolean'];
}
