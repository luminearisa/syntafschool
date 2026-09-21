<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Database\Seeder;

/**
 * Data wilayah contoh untuk pengembangan.
 *
 * Kode provinsi/kabupaten/kota mengikuti format kode BPS (2/4/7/10 digit).
 * Dataset ini SENGAJA hanya memuat sebagian kecil wilayah agar cukup untuk
 * pengembangan dan pengujian. Untuk produksi, impor dataset resmi
 * (BPS / Kemendagri) yang lengkap lewat perintah impor, bukan seeder ini.
 */
class RegionSeeder extends Seeder
{
    /**
     * @var list<array{id: string, name: string}>
     */
    protected array $provinces = [
        ['id' => '31', 'name' => 'DKI Jakarta'],
        ['id' => '32', 'name' => 'Jawa Barat'],
        ['id' => '33', 'name' => 'Jawa Tengah'],
        ['id' => '34', 'name' => 'DI Yogyakarta'],
        ['id' => '35', 'name' => 'Jawa Timur'],
    ];

    /**
     * @var list<array{id: string, province_id: string, name: string, type: string}>
     */
    protected array $cities = [
        ['id' => '3171', 'province_id' => '31', 'name' => 'Kota Jakarta Selatan', 'type' => 'Kota'],
        ['id' => '3172', 'province_id' => '31', 'name' => 'Kota Jakarta Timur', 'type' => 'Kota'],
        ['id' => '3173', 'province_id' => '31', 'name' => 'Kota Jakarta Pusat', 'type' => 'Kota'],
        ['id' => '3174', 'province_id' => '31', 'name' => 'Kota Jakarta Barat', 'type' => 'Kota'],
        ['id' => '3175', 'province_id' => '31', 'name' => 'Kota Jakarta Utara', 'type' => 'Kota'],
        ['id' => '3273', 'province_id' => '32', 'name' => 'Kota Bandung', 'type' => 'Kota'],
        ['id' => '3216', 'province_id' => '32', 'name' => 'Kabupaten Bekasi', 'type' => 'Kabupaten'],
        ['id' => '3374', 'province_id' => '33', 'name' => 'Kota Semarang', 'type' => 'Kota'],
        ['id' => '3310', 'province_id' => '33', 'name' => 'Kabupaten Klaten', 'type' => 'Kabupaten'],
        ['id' => '3471', 'province_id' => '34', 'name' => 'Kota Yogyakarta', 'type' => 'Kota'],
        ['id' => '3402', 'province_id' => '34', 'name' => 'Kabupaten Bantul', 'type' => 'Kabupaten'],
        ['id' => '3404', 'province_id' => '34', 'name' => 'Kabupaten Sleman', 'type' => 'Kabupaten'],
        ['id' => '3578', 'province_id' => '35', 'name' => 'Kota Surabaya', 'type' => 'Kota'],
        ['id' => '3506', 'province_id' => '35', 'name' => 'Kabupaten Kediri', 'type' => 'Kabupaten'],
    ];

    /**
     * Kecamatan: contoh lengkap untuk Kota Yogyakarta + sebagian kota lain.
     *
     * @var list<array{id: string, city_id: string, name: string}>
     */
    protected array $districts = [
        // Kota Yogyakarta (3471) — 14 kecamatan
        ['id' => '3471010', 'city_id' => '3471', 'name' => 'Gondokusuman'],
        ['id' => '3471020', 'city_id' => '3471', 'name' => 'Jetis'],
        ['id' => '3471030', 'city_id' => '3471', 'name' => 'Tegalrejo'],
        ['id' => '3471040', 'city_id' => '3471', 'name' => 'Umbulharjo'],
        ['id' => '3471050', 'city_id' => '3471', 'name' => 'Kotagede'],
        ['id' => '3471060', 'city_id' => '3471', 'name' => 'Mantrijeron'],
        ['id' => '3471070', 'city_id' => '3471', 'name' => 'Kraton'],
        ['id' => '3471080', 'city_id' => '3471', 'name' => 'Gondomanan'],
        ['id' => '3471090', 'city_id' => '3471', 'name' => 'Pakualaman'],
        ['id' => '3471100', 'city_id' => '3471', 'name' => 'Ngampilan'],
        ['id' => '3471110', 'city_id' => '3471', 'name' => 'Wirobrajan'],
        ['id' => '3471120', 'city_id' => '3471', 'name' => 'Gedongtengen'],
        ['id' => '3471130', 'city_id' => '3471', 'name' => 'Danurejan'],
        ['id' => '3471140', 'city_id' => '3471', 'name' => 'Mergangsan'],
        // Contoh kota lain
        ['id' => '3171010', 'city_id' => '3171', 'name' => 'Tebet'],
        ['id' => '3171020', 'city_id' => '3171', 'name' => 'Kebayoran Baru'],
        ['id' => '3173010', 'city_id' => '3173', 'name' => 'Menteng'],
        ['id' => '3173020', 'city_id' => '3173', 'name' => 'Tanah Abang'],
        ['id' => '3273010', 'city_id' => '3273', 'name' => 'Coblong'],
        ['id' => '3273020', 'city_id' => '3273', 'name' => 'Bandung Wetan'],
        ['id' => '3374010', 'city_id' => '3374', 'name' => 'Semarang Tengah'],
        ['id' => '3578010', 'city_id' => '3578', 'name' => 'Genteng'],
        ['id' => '3402010', 'city_id' => '3402', 'name' => 'Bantul'],
        ['id' => '3404010', 'city_id' => '3404', 'name' => 'Depok'],
    ];

    /**
     * Kelurahan/desa: contoh untuk beberapa kecamatan.
     *
     * @var list<array{id: string, district_id: string, name: string, postal_code: string}>
     */
    protected array $villages = [
        // Gondokusuman (3471010)
        ['id' => '3471010001', 'district_id' => '3471010', 'name' => 'Terban', 'postal_code' => '55223'],
        ['id' => '3471010002', 'district_id' => '3471010', 'name' => 'Kotabaru', 'postal_code' => '55224'],
        ['id' => '3471010003', 'district_id' => '3471010', 'name' => 'Gunungketur', 'postal_code' => '55223'],
        ['id' => '3471010004', 'district_id' => '3471010', 'name' => 'Demangan', 'postal_code' => '55221'],
        ['id' => '3471010005', 'district_id' => '3471010', 'name' => 'Baciro', 'postal_code' => '55225'],
        // Jetis (3471020)
        ['id' => '3471020001', 'district_id' => '3471020', 'name' => 'Bumijo', 'postal_code' => '55231'],
        ['id' => '3471020002', 'district_id' => '3471020', 'name' => 'Cokrodiningratan', 'postal_code' => '55233'],
        ['id' => '3471020003', 'district_id' => '3471020', 'name' => 'Gowongan', 'postal_code' => '55232'],
        // Tegalrejo (3471030)
        ['id' => '3471030001', 'district_id' => '3471030', 'name' => 'Tegalrejo', 'postal_code' => '55241'],
        ['id' => '3471030002', 'district_id' => '3471030', 'name' => 'Bener', 'postal_code' => '55243'],
        ['id' => '3471030003', 'district_id' => '3471030', 'name' => 'Kricak', 'postal_code' => '55242'],
        // Contoh kota lain
        ['id' => '3171010001', 'district_id' => '3171010', 'name' => 'Tebet Barat', 'postal_code' => '12810'],
        ['id' => '3171010002', 'district_id' => '3171010', 'name' => 'Tebet Timur', 'postal_code' => '12820'],
        ['id' => '3173010001', 'district_id' => '3173010', 'name' => 'Menteng', 'postal_code' => '10310'],
        ['id' => '3273010001', 'district_id' => '3273010', 'name' => 'Dago', 'postal_code' => '40135'],
        ['id' => '3578010001', 'district_id' => '3578010', 'name' => 'Genteng', 'postal_code' => '60275'],
        ['id' => '3402010001', 'district_id' => '3402010', 'name' => 'Bantul', 'postal_code' => '55711'],
        ['id' => '3404010001', 'district_id' => '3404010', 'name' => 'Caturtunggal', 'postal_code' => '55281'],
    ];

    public function run(): void
    {
        foreach ($this->provinces as $row) {
            Province::firstOrCreate(['id' => $row['id']], ['name' => $row['name']]);
        }

        foreach ($this->cities as $row) {
            City::firstOrCreate(['id' => $row['id']], [
                'province_id' => $row['province_id'],
                'name' => $row['name'],
                'type' => $row['type'],
            ]);
        }

        foreach ($this->districts as $row) {
            District::firstOrCreate(['id' => $row['id']], [
                'city_id' => $row['city_id'],
                'name' => $row['name'],
            ]);
        }

        foreach ($this->villages as $row) {
            Village::firstOrCreate(['id' => $row['id']], [
                'district_id' => $row['district_id'],
                'name' => $row['name'],
                'postal_code' => $row['postal_code'],
            ]);
        }
    }
}
