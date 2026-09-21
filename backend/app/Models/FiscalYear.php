<?php

namespace App\Models;

class FiscalYear extends BaseModel
{
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean'];
}
