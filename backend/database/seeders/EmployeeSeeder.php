<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Gender;
use App\Models\Religion;
use App\Models\School;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Data pegawai contoh: guru mata pelajaran, wali kelas, dan staf.
     *
     * @var list<array<string, mixed>>
     */
    protected array $employees = [
        ['nip' => '198501012010011001', 'full_name' => 'Budi Santoso, S.Pd.', 'gender' => 'L', 'position' => 'Guru Matematika', 'employee_type' => 'guru', 'employment_status' => 'PNS', 'birth_place' => 'Yogyakarta', 'birth_date' => '1985-01-01', 'join_date' => '2010-01-01'],
        ['nip' => '198703152011012002', 'full_name' => 'Siti Nurhaliza, S.Pd.', 'gender' => 'P', 'position' => 'Guru Bahasa Indonesia', 'employee_type' => 'guru', 'employment_status' => 'PNS', 'birth_place' => 'Bandung', 'birth_date' => '1987-03-15', 'join_date' => '2011-01-01'],
        ['nip' => '199002202012011003', 'full_name' => 'Ahmad Fauzi, S.Pd.', 'gender' => 'L', 'position' => 'Guru Fisika', 'employee_type' => 'guru', 'employment_status' => 'PPPK', 'birth_place' => 'Surabaya', 'birth_date' => '1990-02-20', 'join_date' => '2012-01-01'],
        ['nip' => '199104102013012004', 'full_name' => 'Dewi Lestari, S.Pd.', 'gender' => 'P', 'position' => 'Guru Biologi', 'employee_type' => 'guru', 'employment_status' => 'PPPK', 'birth_place' => 'Semarang', 'birth_date' => '1991-04-10', 'join_date' => '2013-01-01'],
        ['nip' => '198809052014011005', 'full_name' => 'Rizky Pratama, S.Kom.', 'gender' => 'L', 'position' => 'Guru Informatika', 'employee_type' => 'guru', 'employment_status' => 'GTY', 'birth_place' => 'Jakarta', 'birth_date' => '1988-09-05', 'join_date' => '2014-01-01'],
        ['nip' => '199206182015012006', 'full_name' => 'Maya Anggraini, S.Pd.', 'gender' => 'P', 'position' => 'Guru Bahasa Inggris', 'employee_type' => 'guru', 'employment_status' => 'GTY', 'birth_place' => 'Medan', 'birth_date' => '1992-06-18', 'join_date' => '2015-01-01'],
        ['nip' => '198911232016011007', 'full_name' => 'Hendra Wijaya, S.Pd.', 'gender' => 'L', 'position' => 'Guru Sejarah', 'employee_type' => 'guru', 'employment_status' => 'Honorer', 'birth_place' => 'Palembang', 'birth_date' => '1989-11-23', 'join_date' => '2016-01-01'],
        ['nip' => '199307302017012008', 'full_name' => 'Rina Kartika, S.Pd.', 'gender' => 'P', 'position' => 'Guru Kimia', 'employee_type' => 'guru', 'employment_status' => 'Honorer', 'birth_place' => 'Denpasar', 'birth_date' => '1993-07-30', 'join_date' => '2017-01-01'],
        ['nip' => '198604122011011009', 'full_name' => 'Agus Setiawan', 'gender' => 'L', 'position' => 'Kepala Tata Usaha', 'employee_type' => 'staf', 'employment_status' => 'PNS', 'birth_place' => 'Bogor', 'birth_date' => '1986-04-12', 'join_date' => '2011-01-01'],
        ['nip' => '199508252018012010', 'full_name' => 'Nur Aisyah', 'gender' => 'P', 'position' => 'Staf Administrasi', 'employee_type' => 'staf', 'employment_status' => 'Honorer', 'birth_place' => 'Makassar', 'birth_date' => '1995-08-25', 'join_date' => '2018-01-01'],
    ];

    public function run(): void
    {
        $school = School::first();
        $religion = Religion::where('name', 'Islam')->first() ?? Religion::first();

        $genders = Gender::pluck('id', 'code');

        foreach ($this->employees as $index => $data) {
            $isTeacher = $data['employee_type'] === 'guru';

            Employee::firstOrCreate(
                ['nip' => $data['nip']],
                [
                    'school_id' => $school?->id,
                    'full_name' => $data['full_name'],
                    'gender_id' => $genders[$data['gender']] ?? null,
                    'religion_id' => $religion?->id,
                    'birth_place' => $data['birth_place'],
                    'birth_date' => $data['birth_date'],
                    'address' => 'Jl. Pendidikan No. '.($index + 1).', Kota Contoh',
                    'phone' => '0812000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'email' => $this->emailFor($data['full_name']),
                    'position' => $data['position'],
                    'employee_type' => $data['employee_type'],
                    'employment_status' => $data['employment_status'],
                    'join_date' => $data['join_date'],
                    'is_teacher' => $isTeacher,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Ubah nama lengkap menjadi email sederhana.
     */
    protected function emailFor(string $fullName): string
    {
        $name = strtolower($fullName);
        $name = str_replace([',', '.'], '', $name);
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return ($parts[0] ?? 'pegawai').'.'.($parts[1] ?? 'syntaf').'@syntaf.sch.id';
    }
}
