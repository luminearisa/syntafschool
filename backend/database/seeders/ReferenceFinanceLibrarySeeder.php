<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Bank;
use App\Models\BookCategory;
use App\Models\DiscountType;
use App\Models\FeeComponent;
use App\Models\ItemCategory;
use App\Models\LeaveType;
use App\Models\Publisher;
use App\Models\Shelf;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class ReferenceFinanceLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $fees = [
            ['code' => 'SPP', 'name' => 'SPP Bulanan', 'type' => 'Bulanan', 'default_amount' => 250000],
            ['code' => 'UGD', 'name' => 'Uang Gedung', 'type' => 'Sekali', 'default_amount' => 1500000],
            ['code' => 'SRG', 'name' => 'Seragam', 'type' => 'Sekali', 'default_amount' => 500000],
            ['code' => 'KGT', 'name' => 'Kegiatan Tahunan', 'type' => 'Tahunan', 'default_amount' => 300000],
        ];
        foreach ($fees as $f) {
            FeeComponent::firstOrCreate(['code' => $f['code']], array_merge($f, ['is_active' => true]));
        }

        $banks = [
            ['code' => 'MANDIRI', 'name' => 'Bank Mandiri'],
            ['code' => 'BRI', 'name' => 'Bank Rakyat Indonesia'],
            ['code' => 'BNI', 'name' => 'Bank Negara Indonesia'],
            ['code' => 'BCA', 'name' => 'Bank Central Asia'],
            ['code' => 'BSI', 'name' => 'Bank Syariah Indonesia'],
        ];
        foreach ($banks as $b) {
            Bank::firstOrCreate(['code' => $b['code']], array_merge($b, ['is_active' => true]));
        }

        DiscountType::firstOrCreate(['name' => 'Beasiswa Prestasi'], ['type' => 'Persen', 'value' => 100, 'is_active' => true]);
        DiscountType::firstOrCreate(['name' => 'KIP'], ['type' => 'Nominal', 'value' => 250000, 'is_active' => true]);
        DiscountType::firstOrCreate(['name' => 'Anak Yatim'], ['type' => 'Persen', 'value' => 50, 'is_active' => true]);

        $categories = [
            ['code' => 'FIK', 'name' => 'Fiksi'],
            ['code' => 'NON', 'name' => 'Non-Fiksi'],
            ['code' => 'PEL', 'name' => 'Pelajaran'],
            ['code' => 'REF', 'name' => 'Referensi'],
            ['code' => 'MAJ', 'name' => 'Majalah'],
        ];
        foreach ($categories as $c) {
            BookCategory::firstOrCreate(['code' => $c['code']], array_merge($c, ['is_active' => true]));
        }

        Publisher::firstOrCreate(['name' => 'Erlangga']);
        Publisher::firstOrCreate(['name' => 'Gramedia']);
        Publisher::firstOrCreate(['name' => 'Yudhistira']);

        Author::firstOrCreate(['name' => 'Unknown']);

        Shelf::firstOrCreate(['code' => 'A1'], ['name' => 'Rak A1']);
        Shelf::firstOrCreate(['code' => 'B1'], ['name' => 'Rak B1']);

        ItemCategory::firstOrCreate(['name' => 'Elektronik'], ['type' => 'Aset']);
        ItemCategory::firstOrCreate(['name' => 'Furniture'], ['type' => 'Aset']);
        ItemCategory::firstOrCreate(['name' => 'ATK'], ['type' => 'Habis Pakai']);

        foreach (['Unit', 'Pcs', 'Box', 'Rim', 'Lusin', 'Set'] as $name) {
            Unit::firstOrCreate(['name' => $name], ['symbol' => $name]);
        }

        LeaveType::firstOrCreate(['name' => 'Sakit'], ['requires_attachment' => true]);
        LeaveType::firstOrCreate(['name' => 'Izin'], ['requires_attachment' => true]);
        LeaveType::firstOrCreate(['name' => 'Keperluan Keluarga'], ['requires_attachment' => false]);
        LeaveType::firstOrCreate(['name' => 'Dispensasi'], ['requires_attachment' => false]);
    }
}
