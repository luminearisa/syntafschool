<?php

namespace App\Models;

class LeaveType extends BaseModel
{
    protected $casts = ['requires_attachment' => 'boolean'];
}
