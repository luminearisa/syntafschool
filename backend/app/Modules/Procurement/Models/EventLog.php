<?php

namespace App\Modules\Procurement\Models;

/** Log peristiwa domain (outbox). Tabel: `proc_events_log`. */
class EventLog extends ProcurementModel
{
    protected $table = 'proc_events_log';

    protected $casts = [
        'payload' => 'array',
        'dispatched_at' => 'datetime',
    ];
}
