<?php

namespace App\Modules\Hrd\Http\Controllers;

use App\Modules\Hrd\Http\Controllers\Concerns\NotImplementedHrd;

/**
 * Endpoint modul HRD untuk HrdLifecycleController.
 *
 * Controller ini belum diimplementasikan (lihat trait NotImplementedHrd):
 * seluruh method membalas 501 sampai layanan disambungkan. Class sengaja
 * tetap ada agar route terdaftar dan `php artisan route:list` tidak gagal.
 */
class HrdLifecycleController
{
    use NotImplementedHrd;
}
