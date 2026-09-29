<?php

namespace App\Modules\Supervisi\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder utama modul supervisi: skala predikat + seluruh instrumen bawaan.
 *
 * Aman dijalankan berulang kali (idempoten): instrumen yang sudah punya versi
 * terbit tidak dibuat ulang.
 */
class SupInstrumentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SupGradeScaleSeeder::class,
            SupAcademicInstrumentSeeder::class,
            SupStaffAppraisalInstrumentSeeder::class,
        ]);
    }
}
