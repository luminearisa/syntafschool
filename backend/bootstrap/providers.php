<?php

use App\Modules\CBT\Providers\CbtServiceProvider;
use App\Modules\LMS\Providers\LmsServiceProvider;
use App\Modules\Supervisi\Providers\SupServiceProvider;
use App\Modules\UKS\Providers\UksServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Modul LMS mendaftarkan konfigurasi, migrasi, route, middleware, dan
    // binding kontrak domainnya sendiri (Integrated/Standalone Mode).
    LmsServiceProvider::class,
    // Modul Supervisi mendaftarkan migrasi `sup_*` dan route /api/v1/supervisi/*.
    SupServiceProvider::class,
    // Modul CBT mendaftarkan migrasi `cbt_*` dan route /api/v1/cbt/*.
    CbtServiceProvider::class,
    // Modul UKS mendaftarkan migrasi `uks_*` dan route /api/v1/uks/*.
    UksServiceProvider::class,
];
