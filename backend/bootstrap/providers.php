<?php

use App\Modules\LMS\Providers\LmsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Modul LMS mendaftarkan konfigurasi, migrasi, route, middleware, dan
    // binding kontrak domainnya sendiri (Integrated/Standalone Mode).
    LmsServiceProvider::class,
];
