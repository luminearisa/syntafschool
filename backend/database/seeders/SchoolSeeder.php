<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::firstOrCreate(
            ['npsn' => '00000000'],
            [
                'name' => 'Syntaf School',
                'jenjang' => 'SMA',
                'status' => 'Swasta',
                'accreditation' => 'A',
                'address' => 'Jl. Pendidikan No. 1',
                'phone' => '0210000000',
                'email' => 'info@syntaf.sch.id',
                'website' => 'https://syntaf.sch.id',
                'headmaster_name' => 'Kepala Sekolah',
                'is_active' => true,
            ]
        );
    }
}
