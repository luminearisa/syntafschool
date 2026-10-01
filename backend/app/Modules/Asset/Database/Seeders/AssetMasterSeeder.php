<?php

namespace App\Modules\Asset\Database\Seeders;

use App\Models\User;
use App\Modules\Asset\Application\Services\AssetCategoryService;
use App\Modules\Asset\Application\Services\InspectionService;
use App\Modules\Asset\Domain\Enums\InspectionType;
use App\Modules\Asset\Models\FacilityType;
use Illuminate\Database\Seeder;

/**
 * Data induk modul: kategori & jenis aset, atributnya, tipe fasilitas, dan
 * templat inspeksi. Semuanya idempoten — dijalankan dua kali tidak menggandakan.
 */
class AssetMasterSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->orderBy('id')->first()
            ?? User::query()->firstOrCreate(
                ['email' => 'asset-seeder@local'],
                ['name' => 'Asset Seeder', 'password' => bcrypt('password')],
            );

        $service = app(AssetCategoryService::class);

        $categories = [
            ['IT', 'Teknologi Informasi', 48, 'STRAIGHT_LINE', '1500000', [
                ['Komputer', ['CPU', 'RAM', 'Storage', 'OS']],
                ['Laptop', ['CPU', 'RAM', 'Storage', 'Layar', 'OS']],
                ['Printer', ['Tipe', 'Kecepatan', 'Koneksi']],
                ['Proyektor', ['Resolusi', 'Lumen', 'Koneksi']],
                ['CCTV', ['Resolusi', 'Penyimpanan', 'Jumlah kanal']],
            ]],
            ['MEBEL', 'Mebel', 96, 'STRAIGHT_LINE', '500000', [
                ['Meja', ['Material', 'Dimensi', 'Warna']],
                ['Kursi', ['Material', 'Dimensi', 'Warna']],
                ['Lemari', ['Material', 'Dimensi', 'Jumlah pintu']],
                ['Tempat Tidur', ['Material', 'Dimensi']],
            ]],
            ['KENDARAAN', 'Kendaraan', 120, 'DECLINING_BALANCE', '1000000', [
                ['Mobil', ['Merek', 'Tahun', 'Nomor rangka']],
                ['Bus', ['Merek', 'Tahun', 'Kapasitas']],
                ['Motor', ['Merek', 'Tahun', 'Nomor rangka']],
            ]],
            ['INFRA', 'Infrastruktur', 120, 'STRAIGHT_LINE', '1000000', [
                ['AC', ['BTU', 'Daya', 'Refrigeran', 'Tegangan']],
                ['Genset', ['Kapasitas', 'Bahan bakar']],
                ['Panel Surya', ['Kapasitas', 'Jenis']],
                ['Pompa Air', ['Kapasitas', 'Daya']],
            ]],
            ['LAB', 'Laboratorium', 60, 'STRAIGHT_LINE', '1000000', [
                ['Mikroskop', ['Perbesaran', 'Jenis']],
                ['Alat Ukur', ['Ketelitian', 'Satuan']],
            ]],
            ['OLAHRAGA', 'Sarana Olahraga', 60, 'STRAIGHT_LINE', '500000', [
                ['Bola', ['Jenis', 'Ukuran']],
                ['Net', ['Jenis', 'Ukuran']],
            ]],
            ['DAPUR', 'Peralatan Dapur', 60, 'STRAIGHT_LINE', '500000', [
                ['Kulkas', ['Kapasitas', 'Daya']],
                ['Kompor', ['Jenis', 'Jumlah tungku']],
            ]],
            ['MEDIS', 'Peralatan Medis', 60, 'STRAIGHT_LINE', '1000000', [
                ['Timbangan', ['Kapasitas', 'Ketelitian']],
                ['Alat Periksa', ['Jenis', 'Fungsi']],
            ]],
            ['KEAMANAN', 'Perlengkapan Keamanan', 48, 'STRAIGHT_LINE', '500000', [
                ['APAR', ['Jenis', 'Kapasitas']],
                ['Alat Evakuasi', ['Jenis', 'Kapasitas']],
            ]],
            ['OFFICE', 'Peralatan Kantor', 60, 'STRAIGHT_LINE', '500000', [
                ['Mesin Fotokopi', ['Kecepatan', 'Kapasitas']],
                ['Mesin Penghancur', ['Kapasitas', 'Jenis']],
            ]],
            ['AV', 'Audio Visual', 48, 'STRAIGHT_LINE', '500000', [
                ['Sound System', ['Daya', 'Jumlah kanal']],
                ['Mikrofon', ['Jenis', 'Koneksi']],
            ]],
            ['ATK', 'Alat Tulis (habis pakai)', null, null, null, []],
            ['KEBERSIHAN', 'Bahan Kebersihan (habis pakai)', null, null, null, []],
        ];

        foreach ($categories as [$code, $name, $life, $method, $threshold, $types]) {
            $kind = in_array($code, ['ATK', 'KEBERSIHAN'], true) ? 'INVENTORY' : 'ASSET';

            $category = \App\Modules\Asset\Models\AssetCategory::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'kind' => $kind,
                    'is_consumable' => $kind === 'INVENTORY',
                    'useful_life_months' => $life,
                    'depreciation_method' => $method,
                    'capitalization_threshold' => $threshold,
                    'default_criticality' => in_array($code, ['INFRA', 'MEDIS', 'KEAMANAN'], true) ? 'HIGH' : 'NORMAL',
                    'inspection_frequency_days' => in_array($code, ['INFRA', 'KEAMANAN'], true) ? 90 : 180,
                    'maintenance_policy' => in_array($code, ['INFRA', 'KENDARAAN'], true)
                        ? ['frequency' => 'DAYS_90', 'interval_days' => 90]
                        : ['frequency' => 'DAYS_180', 'interval_days' => 180],
                ],
            );

            foreach ($types as [$typeName, $attributes]) {
                $type = \App\Modules\Asset\Models\AssetType::query()->updateOrCreate(
                    ['code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $typeName), 0, 4)).'-'.$code],
                    [
                        'category_id' => $category->id,
                        'name' => $typeName,
                        'default_useful_life_months' => $life,
                        'default_criticality' => $category->default_criticality,
                        'default_depreciation_method' => $method,
                    ],
                );

                foreach ($attributes as $index => $attribute) {
                    \App\Modules\Asset\Models\AssetAttribute::query()->updateOrCreate(
                        ['asset_type_id' => $type->id, 'code' => strtoupper(preg_replace('/[^A-Za-z]/', '_', $attribute))],
                        ['label' => $attribute, 'data_type' => 'string', 'sort_order' => $index],
                    );
                }
            }
        }

        $facilityTypes = [
            ['CLASSROOM', 'Ruang Kelas', 'AKADEMIK', 32, true],
            ['LAB', 'Laboratorium', 'AKADEMIK', 30, true],
            ['LIBRARY', 'Perpustakaan', 'AKADEMIK', 60, false],
            ['HALL', 'Aula', 'SERBAGUNA', 300, true],
            ['SPORT', 'Sarana Olahraga', 'OLAHRAGA', 100, true],
            ['MEETING', 'Ruang Rapat', 'KANTOR', 20, true],
            ['MOSQUE', 'Masjid', 'IBADAH', 200, false],
            ['DORMITORY', 'Asrama', 'ASRAMA', 40, false],
            ['CLINIC', 'Klinik', 'KESEHATAN', 10, false],
            ['KITCHEN', 'Dapur', 'DAPUR', 20, false],
            ['WAREHOUSE', 'Gudang', 'GUDANG', 0, false],
            ['PAVILION', 'Lapangan Terbuka', 'OLAHRAGA', 500, true],
        ];

        foreach ($facilityTypes as [$code, $name, $category, $capacity, $approval]) {
            FacilityType::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'default_capacity' => $capacity ?: null,
                    'requires_approval' => $approval,
                    'is_bookable' => $code !== 'WAREHOUSE',
                ],
            );
        }

        $templates = [
            ['RUTIN-AC', 'Inspeksi rutin AC', InspectionType::PREVENTIVE, ['CONDITION', 'FUNCTIONALITY', 'CLEANLINESS', 'SAFETY', 'COMPLETENESS']],
            ['RUTIN-LAB', 'Inspeksi laboratorium', InspectionType::SAFETY, ['CONDITION', 'FUNCTIONALITY', 'SAFETY', 'COMPLETENESS']],
            ['RUTIN-RUANG', 'Inspeksi ruang', InspectionType::ROUTINE, ['CONDITION', 'CLEANLINESS', 'COMPLETENESS']],
        ];

        foreach ($templates as [$code, $name, $type, $items]) {
            \App\Modules\Asset\Models\AssetInspectionTemplate::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'inspection_type' => $type->value,
                    'checklist' => array_map(fn (string $item) => ['code' => $item, 'label' => ucfirst(strtolower($item))], $items),
                ],
            );
        }

        $this->command?->info('Data induk Asset & Sarpras siap: '.count($categories).' kategori, '.count($facilityTypes).' jenis fasilitas.');
    }
}
