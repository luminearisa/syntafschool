<?php

namespace App\Modules\Clinic\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master data awal Modul Clinic / UKS: lokasi layanan, daftar keluhan, jenis
 * tindakan, kode diagnosis, dan master obat dasar.
 *
 * Semua baris dibuat dengan `firstOrCreate` sehingga seeder aman dijalankan
 * berulang pada basis data yang sudah berisi data sekolah.
 *
 * Obat dipetakan ke item Inventory **bila item dengan nama serupa sudah ada**.
 * Bila belum ada, obat tetap tercatat sebagai master klinis tanpa tautan stok —
 * dan penyerahan obatnya akan mengembalikan status NOT_AVAILABLE, bukan
 * diam-diam menganggap stok ada.
 */
class ClinicMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->locations();
        $this->complaints();
        $this->procedureTypes();
        $this->diagnoses();
        $this->medications();
    }

    private function locations(): void
    {
        $rows = [
            ['code' => 'UKS-PA', 'name' => 'UKS Putra', 'type' => 'UKS', 'location' => 'Gedung Asrama Putra Lt. 1', 'is_default' => false, 'bed_capacity' => 4],
            ['code' => 'UKS-PI', 'name' => 'UKS Putri', 'type' => 'UKS', 'location' => 'Gedung Asrama Putri Lt. 1', 'is_default' => false, 'bed_capacity' => 4],
            ['code' => 'KLINIK-UTAMA', 'name' => 'Klinik Utama Sekolah', 'type' => 'CLINIC', 'location' => 'Gedung Utama Lt. 1', 'is_default' => true, 'bed_capacity' => 6],
        ];

        foreach ($rows as $row) {
            DB::table('clinic_clinics')->updateOrInsert(
                ['code' => $row['code']],
                $row + [
                    'status' => 'ACTIVE',
                    'description' => null,
                    'phone' => null,
                    'operating_hours' => json_encode(['mon' => '07:00-16:00', 'tue' => '07:00-16:00', 'wed' => '07:00-16:00', 'thu' => '07:00-16:00', 'fri' => '07:00-16:00', 'sat' => '07:00-12:00']),
                    'supports_observation' => true,
                    'supports_emergency' => $row['type'] === 'CLINIC',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function complaints(): void
    {
        $rows = [
            ['DEMAM', 'Demam', 'UMUM'],
            ['BATUK', 'Batuk', 'PERNAPASAN'],
            ['PILEK', 'Pilek', 'PERNAPASAN'],
            ['SAKIT_KEPALA', 'Sakit kepala', 'SARAF'],
            ['NYERI_PERUT', 'Nyeri perut', 'PENCERNAAN'],
            ['MUAL', 'Mual', 'PENCERNAAN'],
            ['MUNTAB', 'Muntah', 'PENCERNAAN'],
            ['DIARE', 'Diare', 'PENCERNAAN'],
            ['PUSING', 'Pusing', 'SARAF'],
            ['LUKA', 'Luka', 'CEDERA'],
            ['CEDERA', 'Cedera', 'CEDERA'],
            ['SAKIT_GIGI', 'Sakit gigi', 'MULUT'],
            ['SAKIT_TENGGOROKAN', 'Sakit tenggorokan', 'PERNAPASAN'],
            ['SESAK', 'Sesak napas', 'PERNAPASAN'],
            ['ALERGI', 'Reaksi alergi', 'IMUNOLOGI'],
            ['LEMAH', 'Badan lemas', 'UMUM'],
            ['NYERI_OTOT', 'Nyeri otot', 'MUSKULOSKELETAL'],
            ['INSOMNIA', 'Sulit tidur', 'UMUM'],
        ];

        foreach ($rows as $index => [$code, $name, $category]) {
            DB::table('clinic_complaints')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'keywords' => json_encode(array_map('trim', explode(' ', mb_strtolower($name)))),
                    'is_infectious' => in_array($code, ['DEMAM', 'BATUK', 'PILEK', 'DIARE', 'MUNTAB'], true),
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function procedureTypes(): void
    {
        $rows = [
            ['P3K_DASAR', 'Pertolongan pertama dasar', 'BASIC_CARE', false],
            ['BERSIHKAN_LUKA', 'Membersihkan luka', 'WOUND_CARE', false],
            ['PERBAN', 'Pembalutan luka', 'WOUND_CARE', false],
            ['KOMPRES_DINGIN', 'Kompres dingin', 'BASIC_CARE', false],
            ['KOMPRES_HANGAT', 'Kompres hangat', 'BASIC_CARE', false],
            ['OBSERVASI', 'Observasi', 'MONITORING', false],
            ['PEMERIKSAAN_DASAR', 'Pemeriksaan dasar', 'DIAGNOSTIC', false],
            ['PEMBERIAN_OBAT', 'Pemberian obat', 'BASIC_CARE', false],
            ['IMUNISASI', 'Imunisasi', 'BASIC_CARE', true],
        ];

        foreach ($rows as $index => [$code, $name, $category, $consent]) {
            DB::table('clinic_procedure_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'requires_consent' => $consent,
                    'is_billable' => false,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function diagnoses(): void
    {
        $rows = [
            ['A01', 'Demam tifoid', 'INFEKSI'],
            ['A09', 'Diare dan gastroenteritis', 'INFEKSI'],
            ['A15', 'Tuberkulosis paru', 'INFEKSI'],
            ['B34', 'Infeksi virus', 'INFEKSI'],
            ['H10', 'Konjungtivitis', 'MATA'],
            ['H66', 'Otitis media', 'THT'],
            ['J00', 'Nasofaringitis akut (pilek)', 'PERNAPASAN'],
            ['J02', 'Faringitis akut', 'PERNAPASAN'],
            ['J06', 'Infeksi saluran napas atas', 'PERNAPASAN'],
            ['J45', 'Asma', 'PERNAPASAN'],
            ['K30', 'Dispepsia', 'PENCERNAAN'],
            ['K52', 'Gastritis dan duodenitis', 'PENCERNAAN'],
            ['L03', 'Selulitis', 'KULIT'],
            ['M54', 'Dorsalgia (nyeri punggung)', 'MUSKULOSKELETAL'],
            ['N39', 'Infeksi saluran kemih', 'UROGENITAL'],
            ['R10', 'Nyeri perut', 'GEJALA'],
            ['R42', 'Pusing dan kliyengan', 'GEJALA'],
            ['R50', 'Demam tidak spesifik', 'GEJALA'],
            ['R51', 'Sakit kepala', 'GEJALA'],
            ['S00', 'Cedera kepala ringan', 'CEDERA'],
            ['S60', 'Cedera jari tangan', 'CEDERA'],
            ['S93', 'Terkilir pergelangan kaki', 'CEDERA'],
            ['T14', 'Cedera tidak spesifik', 'CEDERA'],
            ['T78', 'Reaksi alergi', 'IMUNOLOGI'],
            ['Z00', 'Pemeriksaan kesehatan umum', 'PEMERIKSAAN'],
        ];

        foreach ($rows as [$code, $name, $category]) {
            DB::table('clinic_diagnoses_master')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'coding_system' => 'INTERNAL',
                    'is_infectious' => in_array(substr($code, 0, 1), ['A', 'B'], true),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function medications(): void
    {
        $rows = [
            ['PAR500', 'Paracetamol', 'Paracetamol', 'TABLET', '500 mg', 'tablet', 'ORAL', 'ANALGESIK', false],
            ['PARSIR', 'Paracetamol Sirup', 'Paracetamol', 'SYRUP', '120 mg/5 ml', 'botol', 'ORAL', 'ANALGESIK', false],
            ['IBU400', 'Ibuprofen', 'Ibuprofen', 'TABLET', '400 mg', 'tablet', 'ORAL', 'ANALGESIK', true],
            ['AMO500', 'Amoksisilin', 'Amoxicillin', 'CAPSULE', '500 mg', 'kapsul', 'ORAL', 'ANTIBIOTIK', true],
            ['ORS', 'Oralit', 'Oral Rehydration Salt', 'OTHER', 'sachet', 'sachet', 'ORAL', 'REHIDRASI', false],
            ['ANTASIDA', 'Antasida', 'Aluminium Hydroxide', 'TABLET', '400 mg', 'tablet', 'ORAL', 'LAMBUNG', false],
            ['CTM', 'CTM', 'Chlorpheniramine', 'TABLET', '4 mg', 'tablet', 'ORAL', 'ANTIHISTAMIN', false],
            ['SALEP_ANTIBIOTIK', 'Salep antibiotik', 'Mupirocin', 'OINTMENT', '2%', 'tube', 'TOPICAL', 'ANTIBIOTIK', true],
            ['POVIDONE', 'Povidone iodine', 'Povidone Iodine', 'OTHER', '10%', 'botol', 'TOPICAL', 'ANTISEPTIK', false],
            ['BETADINE', 'Betadine larutan', 'Povidone Iodine', 'OTHER', '30 ml', 'botol', 'TOPICAL', 'ANTISEPTIK', false],
            ['TENSIMIN', 'Obat tetes mata', 'Chloramphenicol', 'DROPS', '0,5%', 'botol', 'OPHTHALMIC', 'MATA', true],
            ['SALBUTAMOL', 'Salbutamol inhaler', 'Salbutamol', 'OTHER', '100 mcg', 'inhaler', 'OTHER', 'ASMA', true],
        ];

        foreach ($rows as [$code, $name, $generic, $form, $strength, $unit, $route, $category, $rx]) {
            $inventoryItemId = DB::table('inventory_items')
                ->where('name', 'like', '%'.$generic.'%')
                ->orWhere('name', 'like', '%'.explode(' ', $name)[0].'%')
                ->value('id');

            DB::table('clinic_medications')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'generic_name' => $generic,
                    'brand_name' => null,
                    'dosage_form' => $form,
                    'strength' => $strength,
                    'unit' => $unit,
                    'route' => $route,
                    'category' => $category,
                    'requires_prescription' => $rx,
                    'is_controlled' => false,
                    'status' => 'ACTIVE',
                    'inventory_item_id' => $inventoryItemId,
                    'notes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
