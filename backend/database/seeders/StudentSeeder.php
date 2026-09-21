<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\EducationBackground;
use App\Models\Gender;
use App\Models\Guardian;
use App\Models\GuardianRelation;
use App\Models\IncomeRange;
use App\Models\Occupation;
use App\Models\Religion;
use App\Models\ResidenceType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\StudentStatus;
use App\Models\Transportation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A3 — Data siswa contoh beserta wali murid dan riwayat kelas.
 *
 * Keluarga dibuat berpasangan (dua anak per keluarga) supaya relasi
 * banyak-ke-banyak siswa–wali benar-benar terpakai: dua siswa bersaudara
 * berbagi wali yang sama.
 */
class StudentSeeder extends Seeder
{
    /** @var list<string> */
    protected array $maleNames = [
        'Adi Nugroho', 'Bagas Prasetyo', 'Cahyo Wibowo', 'Dimas Anggara', 'Eko Saputra',
        'Fajar Ramadhan', 'Gilang Pratama', 'Hendra Kusuma', 'Irfan Maulana', 'Joko Susilo',
        'Krisna Bayu', 'Lukman Hakim', 'Muhammad Rizky', 'Nanda Setiawan', 'Oka Mahendra',
        'Putra Wijaya', 'Rafi Alfarizi', 'Satria Yudha', 'Taufik Hidayat', 'Umar Abdullah',
    ];

    /** @var list<string> */
    protected array $femaleNames = [
        'Ayu Lestari', 'Bella Safira', 'Citra Dewi', 'Dinda Ayu', 'Eka Putri',
        'Fitri Handayani', 'Gita Puspita', 'Hana Salsabila', 'Indah Permata', 'Jihan Aulia',
        'Kirana Maharani', 'Laras Widya', 'Maya Anggraini', 'Nabila Zahra', 'Oktaviani Putri',
        'Priska Melati', 'Rahma Wulandari', 'Sinta Nurhaliza', 'Tiara Ananda', 'Ulfa Ramadhani',
    ];

    public function run(): void
    {
        $year = AcademicYear::where('is_active', true)->first() ?? AcademicYear::first();
        $classes = SchoolClass::orderBy('id')->get();

        if (! $year || $classes->isEmpty()) {
            return;
        }

        $school = School::first();
        $activeStatusId = StudentStatus::where('name', 'Aktif')->value('id');
        $maleId = Gender::where('code', 'L')->value('id');
        $femaleId = Gender::where('code', 'P')->value('id');
        $islamId = Religion::where('name', 'Islam')->value('id');

        $relations = GuardianRelation::pluck('id', 'name');
        $occupationIds = Occupation::pluck('id')->all();
        $incomeIds = IncomeRange::pluck('id')->all();
        $educationIds = EducationBackground::pluck('id')->all();
        $residenceIds = ResidenceType::pluck('id')->all();
        $transportationIds = Transportation::pluck('id')->all();

        $villages = $this->villages();
        if ($villages->isEmpty()) {
            return;
        }

        $familyCount = 20;
        $sequence = 0;

        foreach (range(0, $familyCount - 1) as $family) {
            $guardians = $this->createFamilyGuardians(
                $family,
                $relations,
                $occupationIds,
                $incomeIds,
                $educationIds,
                $maleId,
                $femaleId,
                $islamId,
                $villages->get($family % $villages->count()),
            );

            // Dua anak per keluarga, disebar ke kelas berbeda.
            foreach ([0, 1] as $childIndex) {
                $class = $classes[($family + $childIndex) % $classes->count()];
                $isMale = ($family + $childIndex) % 2 === 0;
                $name = $isMale
                    ? $this->maleNames[$sequence % count($this->maleNames)]
                    : $this->femaleNames[$sequence % count($this->femaleNames)];

                $sequence++;
                $village = $villages->get(($family + $childIndex) % $villages->count());
                $birthYear = $this->birthYearFor($class->name, $year);

                $student = Student::firstOrCreate(
                    ['nis' => sprintf('%s%04d', $year->start_date?->format('Y') ?? '2025', $sequence)],
                    [
                        'school_id' => $school?->id,
                        'nisn' => sprintf('00%08d', 1000000 + $sequence),
                        'full_name' => $name,
                        'nick_name' => explode(' ', $name)[0],
                        'gender_id' => $isMale ? $maleId : $femaleId,
                        'religion_id' => $islamId,
                        'birth_place' => $village->city_name ?? 'Yogyakarta',
                        'birth_date' => sprintf('%d-%02d-%02d', $birthYear, ($sequence % 12) + 1, ($sequence % 27) + 1),
                        'blood_type' => ['A', 'B', 'AB', 'O'][$sequence % 4],
                        'address' => 'Jl. Pendidikan No. '.$sequence.', RT 0'.(($sequence % 8) + 1).'/RW 0'.(($sequence % 5) + 1),
                        'province_id' => $village->province_id,
                        'city_id' => $village->city_id,
                        'district_id' => $village->district_id,
                        'village_id' => $village->village_id,
                        'postal_code' => $village->postal_code,
                        'phone' => '0812'.str_pad((string) (30000000 + $sequence), 8, '0', STR_PAD_LEFT),
                        'email' => strtolower(str_replace(' ', '.', $name)).'@siswa.syntaf.test',
                        'residence_type_id' => $residenceIds ? $residenceIds[$sequence % count($residenceIds)] : null,
                        'transportation_id' => $transportationIds ? $transportationIds[$sequence % count($transportationIds)] : null,
                        'child_order' => $childIndex + 1,
                        'sibling_count' => 2,
                        'previous_school' => 'SMP Negeri '.((($sequence % 5) + 1)),
                        'admission_date' => $year->start_date?->toDateString(),
                        'status_id' => $activeStatusId,
                        'is_active' => true,
                    ],
                );

                // Relasi wali — anak pertama jadi wali utama.
                $student->guardians()->syncWithoutDetaching([
                    $guardians['ayah']->id => [
                        'guardian_relation_id' => $relations['Ayah'] ?? null,
                        'is_primary' => true,
                    ],
                    $guardians['ibu']->id => [
                        'guardian_relation_id' => $relations['Ibu'] ?? null,
                        'is_primary' => false,
                    ],
                ]);

                // Rombel tahun ajaran aktif.
                ClassStudent::updateOrCreate(
                    ['student_id' => $student->id, 'academic_year_id' => $year->id],
                    ['school_class_id' => $class->id, 'status' => 'aktif'],
                );

                // Riwayat kelas.
                StudentHistory::updateOrCreate(
                    ['student_id' => $student->id, 'academic_year_id' => $year->id],
                    [
                        'school_class_id' => $class->id,
                        'education_level_id' => $class->education_level_id,
                        'status' => str_starts_with($class->name, 'X ') ? 'baru' : 'naik',
                        'note' => 'Data awal hasil seeder pengembangan.',
                    ],
                );
            }
        }
    }

    /**
     * Buat pasangan wali (ayah & ibu) untuk satu keluarga.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $relations
     * @param  list<int>  $occupationIds
     * @param  list<int>  $incomeIds
     * @param  list<int>  $educationIds
     * @return array{ayah: Guardian, ibu: Guardian}
     */
    protected function createFamilyGuardians(
        int $family,
        $relations,
        array $occupationIds,
        array $incomeIds,
        array $educationIds,
        ?int $maleId,
        ?int $femaleId,
        ?int $islamId,
        object $village,
    ): array {
        $surname = ['Santoso', 'Wijaya', 'Hartono', 'Nugroho', 'Pratama', 'Setiawan', 'Hidayat', 'Saputra', 'Kusuma', 'Ramadhan',
            'Lestari', 'Handayani', 'Anggraini', 'Wulandari', 'Permata', 'Safira', 'Maharani', 'Ananda', 'Salsabila', 'Aulia'][$family % 20];

        $address = 'Jl. Keluarga '.$surname.' No. '.($family + 1).', '.($village->village_name ?? 'Desa Contoh');

        $ayah = Guardian::firstOrCreate(
            ['full_name' => 'Bapak '.$surname],
            [
                'gender_id' => $maleId,
                'religion_id' => $islamId,
                'address' => $address,
                'phone' => '0813'.str_pad((string) (40000000 + $family), 8, '0', STR_PAD_LEFT),
                'email' => strtolower($surname).'.ayah@wali.syntaf.test',
                'occupation_id' => $occupationIds ? $occupationIds[$family % count($occupationIds)] : null,
                'income_range_id' => $incomeIds ? $incomeIds[$family % count($incomeIds)] : null,
                'education_background_id' => $educationIds ? $educationIds[$family % count($educationIds)] : null,
            ],
        );

        $ibu = Guardian::firstOrCreate(
            ['full_name' => 'Ibu '.$surname],
            [
                'gender_id' => $femaleId,
                'religion_id' => $islamId,
                'address' => $address,
                'phone' => '0814'.str_pad((string) (50000000 + $family), 8, '0', STR_PAD_LEFT),
                'email' => strtolower($surname).'.ibu@wali.syntaf.test',
                'occupation_id' => $occupationIds ? $occupationIds[($family + 3) % count($occupationIds)] : null,
                'income_range_id' => $incomeIds ? $incomeIds[($family + 1) % count($incomeIds)] : null,
                'education_background_id' => $educationIds ? $educationIds[($family + 2) % count($educationIds)] : null,
            ],
        );

        return ['ayah' => $ayah, 'ibu' => $ibu];
    }

    /**
     * Ambil rantai wilayah lengkap (desa → kecamatan → kota → provinsi).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function villages()
    {
        return DB::table('villages as v')
            ->join('districts as d', 'd.id', '=', 'v.district_id')
            ->join('cities as c', 'c.id', '=', 'd.city_id')
            ->select([
                'v.id as village_id',
                'v.name as village_name',
                'v.postal_code',
                'd.id as district_id',
                'c.id as city_id',
                'c.name as city_name',
                'c.province_id',
            ])
            ->orderBy('v.id')
            ->get();
    }

    /**
     * Perkiraan tahun lahir berdasarkan jenjang kelas.
     */
    protected function birthYearFor(string $className, AcademicYear $year): int
    {
        $reference = (int) ($year->start_date?->format('Y') ?? date('Y'));

        if (str_starts_with($className, 'XII')) {
            return $reference - 18;
        }

        if (str_starts_with($className, 'XI')) {
            return $reference - 17;
        }

        return $reference - 16;
    }
}
