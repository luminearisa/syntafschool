# Route modul per-domain

Setiap domain akademik mendaftarkan route-nya di file sendiri di folder ini
(mis. `academic_calendar.php`, `curriculum.php`).

## Kontrak

- File **tidak** membungkus route dengan `Route::prefix('v1')` atau
  `middleware('auth:sanctum')` — semuanya sudah otomatis karena file ini
  di-`require` di dalam grup tersebut oleh `routes/api.php`.
- Tulis route apa adanya:

  ```php
  <?php

  use App\Http\Controllers\Api\AcademicCalendarController;
  use Illuminate\Support\Facades\Route;

  Route::get('academic-events', [AcademicCalendarController::class, 'index'])
      ->middleware('permission:academic.view')
      ->name('academic-events.index');
  ```

- Otorisasi **wajib** per-route lewat `middleware('permission:<modul>.<aksi>')`,
  bukan di dalam controller.
- Jangan menyunting `routes/api.php`. Cukup tambahkan file baru di folder ini.

## Kenapa begini

`routes/api.php` memuat semua file di folder ini secara otomatis:

```php
foreach (glob(__DIR__.'/api/*.php') ?: [] as $domainRoutes) {
    require $domainRoutes;
}
```

Tujuannya agar beberapa modul bisa dikerjakan bersamaan tanpa berebut
satu file route yang sama.
