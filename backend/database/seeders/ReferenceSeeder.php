<?php

namespace Database\Seeders;

use App\Models\EducationBackground;
use App\Models\Extracurricular;
use App\Models\Gender;
use App\Models\GuardianRelation;
use App\Models\IncomeRange;
use App\Models\Occupation;
use App\Models\Religion;
use App\Models\ResidenceType;
use App\Models\StudentStatus;
use App\Models\Transportation;
use App\Models\ViolationType;
use Illuminate\Database\Seeder;

class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu', 'Lainnya'] as $name) {
            Religion::firstOrCreate(['name' => $name]);
        }

        Gender::firstOrCreate(['code' => 'L'], ['name' => 'Laki-laki']);
        Gender::firstOrCreate(['code' => 'P'], ['name' => 'Perempuan']);

        $occupations = ['Tidak Bekerja', 'PNS', 'TNI', 'POLRI', 'Pegawai Swasta', 'Wiraswasta', 'Petani', 'Nelayan', 'Buruh', 'Guru/Dosen', 'Dokter', 'Pensiunan', 'Ibu Rumah Tangga', 'Lainnya'];
        foreach ($occupations as $name) {
            Occupation::firstOrCreate(['name' => $name]);
        }

        $educations = ['Tidak Sekolah' => 0, 'SD' => 1, 'SMP' => 2, 'SMA/SMK' => 3, 'D3' => 4, 'S1' => 5, 'S2' => 6, 'S3' => 7];
        foreach ($educations as $name => $level) {
            EducationBackground::firstOrCreate(['name' => $name], ['level_number' => $level]);
        }

        $incomes = [
            ['label' => '< Rp 1.000.000', 'min_value' => 0, 'max_value' => 1000000],
            ['label' => 'Rp 1.000.000 - Rp 2.500.000', 'min_value' => 1000000, 'max_value' => 2500000],
            ['label' => 'Rp 2.500.000 - Rp 5.000.000', 'min_value' => 2500000, 'max_value' => 5000000],
            ['label' => 'Rp 5.000.000 - Rp 10.000.000', 'min_value' => 5000000, 'max_value' => 10000000],
            ['label' => '> Rp 10.000.000', 'min_value' => 10000000, 'max_value' => null],
        ];
        foreach ($incomes as $income) {
            IncomeRange::firstOrCreate(['label' => $income['label']], $income);
        }

        foreach (['Ayah', 'Ibu', 'Wali', 'Kakak', 'Kakek', 'Nenek', 'Paman', 'Bibi', 'Lainnya'] as $name) {
            GuardianRelation::firstOrCreate(['name' => $name]);
        }

        foreach (['Bersama Orang Tua', 'Bersama Wali', 'Pesantren', 'Kos', 'Asrama', 'Lainnya'] as $name) {
            ResidenceType::firstOrCreate(['name' => $name]);
        }

        foreach (['Jalan Kaki', 'Sepeda', 'Sepeda Motor', 'Mobil', 'Antar Jemput', 'Angkutan Umum', 'Lainnya'] as $name) {
            Transportation::firstOrCreate(['name' => $name]);
        }

        foreach (['Aktif', 'Lulus', 'Mutasi', 'Keluar', 'Tinggal Kelas'] as $name) {
            StudentStatus::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        $violations = [
            ['name' => 'Terlambat masuk sekolah', 'category' => 'Ringan', 'point' => 1],
            ['name' => 'Tidak mengerjakan tugas', 'category' => 'Ringan', 'point' => 2],
            ['name' => 'Tidak mengikuti pelajaran', 'category' => 'Sedang', 'point' => 5],
            ['name' => 'Membolos', 'category' => 'Sedang', 'point' => 10],
            ['name' => 'Merokok di lingkungan sekolah', 'category' => 'Berat', 'point' => 25],
            ['name' => 'Berkelahi', 'category' => 'Berat', 'point' => 50],
        ];
        foreach ($violations as $v) {
            ViolationType::firstOrCreate(['name' => $v['name']], $v);
        }

        $extracurriculars = ['Pramuka', 'PMR', 'Paskibra', 'OSIS', 'Basket', 'Futsal', 'Voli', 'Bulu Tangkis', 'Seni Tari', 'Paduan Suara', 'Rohis', 'English Club'];
        foreach ($extracurriculars as $name) {
            Extracurricular::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
