<?php

namespace Tests\Feature\Clinic;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\InventoryItem;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Modules\Clinic\Database\Seeders\ClinicMasterSeeder;
use App\Modules\Clinic\Database\Seeders\ClinicPermissionSeeder;
use App\Modules\Clinic\Models\ClinicLocation;
use App\Modules\Clinic\Models\ClinicMedication;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicStaff;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian Modul Clinic / UKS.
 *
 * Menyiapkan dunia kecil yang realistis: satu lokasi layanan, satu kelas
 * dengan wali kelas, dua siswa beserta wali masing-masing, satu item Inventory
 * dengan stok, dan petugas klinis dengan peran berbeda.
 *
 * Dua siswa sengaja dibuat agar setiap uji privasi benar-benar menguji
 * "orang tua A vs anak B", bukan sekadar peran tanpa tetangga.
 */
abstract class ClinicTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected const ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'guru', 'wali-kelas',
        'staf', 'siswa', 'wali-murid', 'petugas-kesehatan', 'auditor',
        // Peran klinis dibuat oleh ClinicPermissionSeeder; didaftarkan di sini
        // agar urutan pembuatan peran pada pengujian tetap eksplisit.
        'admin-klinik', 'dokter', 'perawat', 'petugas-uks', 'petugas-farmasi', 'resepsionis-klinik',
    ];

    protected AcademicYear $year;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(ClinicPermissionSeeder::class);
        $this->seed(ClinicMasterSeeder::class);

        $this->year = AcademicYear::query()->create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);

        Semester::query()->create([
            'academic_year_id' => $this->year->id,
            'name' => 'Ganjil',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ pengguna

    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function accountFor(string $role, object $entity, array $attributes = []): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'userable_type' => $entity->getMorphClass(),
            'userable_id' => $entity->id,
            ...$attributes,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function actAs(User $user): User
    {
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // ------------------------------------------------------------- klinik

    protected function clinic(string $code = 'KLINIK-UTAMA'): ClinicLocation
    {
        return ClinicLocation::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Petugas klinis: akun + baris `clinic_staff` + peran ERP yang sesuai.
     *
     * Peran ERP sengaja dipetakan dari peran klinis (mis. NURSE → `perawat`)
     * karena izin route diperiksa terhadap peran ERP, sedangkan kewenangan
     * klinis rinci diperiksa ClinicAccessService dari `clinic_staff.role`.
     *
     * @return array{user: User, staff: ClinicStaff, employee: Employee}
     */
    protected function clinicStaff(string $clinicRole, ?string $erpRole = null, ?ClinicLocation $clinic = null): array
    {
        $erpRole ??= match ($clinicRole) {
            'DOCTOR' => 'dokter',
            'NURSE' => 'perawat',
            'PHARMACY_OFFICER' => 'petugas-farmasi',
            'CLINIC_ADMIN' => 'admin-klinik',
            'UKS_OFFICER' => 'petugas-uks',
            'CLINIC_RECEPTIONIST' => 'resepsionis-klinik',
            default => 'petugas-kesehatan',
        };

        $employee = Employee::query()->create([
            'full_name' => 'Petugas '.$clinicRole.' '.random_int(100, 999),
            'employee_type' => 'staf',
            'is_teacher' => false,
            'is_active' => true,
        ]);

        $user = $this->accountFor($erpRole, $employee);

        $staff = ClinicStaff::query()->create([
            'user_id' => $user->id,
            'clinic_id' => ($clinic ?? $this->clinic())->id,
            'role' => $clinicRole,
            'status' => 'ACTIVE',
            'start_date' => now()->subYear()->toDateString(),
        ]);

        return ['user' => $user, 'staff' => $staff, 'employee' => $employee];
    }

    // -------------------------------------------------------------- siswa

    protected function makeClass(?Employee $homeroom = null): SchoolClass
    {
        return SchoolClass::query()->create([
            'academic_year_id' => $this->year->id,
            'name' => 'VII '.random_int(1, 9),
            'code' => 'K'.random_int(10000, 99999),
            'homeroom_teacher_id' => $homeroom?->id,
            'is_active' => true,
        ]);
    }

    protected function makeStudent(string $name, ?SchoolClass $class = null): Student
    {
        $student = Student::query()->create([
            'nis' => 'NIS'.random_int(100000, 999999),
            'full_name' => $name,
            'is_active' => true,
        ]);

        if ($class !== null) {
            ClassStudent::query()->create([
                'school_class_id' => $class->id,
                'student_id' => $student->id,
                'academic_year_id' => $class->academic_year_id,
                'status' => 'aktif',
            ]);
        }

        return $student;
    }

    /** @return array{student: Student, patient: ClinicPatient, guardian: Guardian, guardian_user: User} */
    protected function makePatientWithGuardian(string $name, ?SchoolClass $class = null): array
    {
        $student = $this->makeStudent($name, $class ?? $this->class ??= $this->makeClass());

        $guardian = Guardian::query()->create([
            'full_name' => 'Wali '.$name,
            'phone' => '0812'.random_int(10000000, 99999999),
        ]);

        $student->guardians()->attach($guardian->id, ['is_primary' => true]);

        $guardianUser = $this->accountFor('wali-murid', $guardian);

        $patient = app(\App\Modules\Clinic\Application\Services\PatientService::class)
            ->forStudent((int) $student->id);

        return [
            'student' => $student,
            'patient' => $patient,
            'guardian' => $guardian,
            'guardian_user' => $guardianUser,
        ];
    }

    // ---------------------------------------------------------------- obat

    protected function inventoryItem(string $name = 'Paracetamol 500 mg', float $quantity = 100): InventoryItem
    {
        return InventoryItem::query()->create([
            'sku' => 'SKU'.random_int(10000, 99999),
            'name' => $name,
            'quantity' => $quantity,
            'minimum_quantity' => 5,
            'is_active' => true,
        ]);
    }

    /**
     * Master obat khusus pengujian. Kode dibuat unik karena seeder master sudah
     * memakai kode baku (mis. PAR500) pada katalog obat sekolah.
     */
    protected function medication(?string $code = null, ?int $inventoryItemId = null, string $name = 'Paracetamol'): ClinicMedication
    {
        return ClinicMedication::query()->create([
            'code' => $code ?? 'T'.random_int(100000, 999999),
            'name' => $name,
            'dosage_form' => 'TABLET',
            'unit' => 'tablet',
            'route' => 'ORAL',
            'status' => 'ACTIVE',
            'requires_prescription' => true,
            'inventory_item_id' => $inventoryItemId,
        ]);
    }

    // ----------------------------------------------------------- kunjungan

    protected function visit(ClinicPatient $patient, array $overrides = []): ClinicVisit
    {
        return app(\App\Modules\Clinic\Application\Services\VisitService::class)
            ->register(array_merge([
                'patient_id' => $patient->id,
                'clinic_id' => $this->clinic()->id,
                'chief_complaint' => 'Demam',
            ], $overrides), User::query()->firstOrFail());
    }
}
