<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\EducationLevel;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    private function student(string $nis, ?string $admissionDate = null): Student
    {
        return Student::query()->create([
            'nis' => $nis,
            'full_name' => 'Siswa '.$nis,
            'admission_date' => $admissionDate,
            'is_active' => true,
        ]);
    }

    public function test_dashboard_mengembalikan_ringkasan_dan_tren_yang_berasal_dari_data_sekolah(): void
    {
        $this->userWithPermissions('student.view', 'academic.view', 'finance.view');

        $today = Carbon::today();
        $this->student('DASH001', $today->toDateString());
        $lastMonthStudent = $this->student('DASH002', $today->copy()->subMonth()->startOfMonth()->toDateString());

        $year = AcademicYear::query()->create([
            'name' => '2026/2027',
            'start_date' => $today->copy()->startOfYear()->toDateString(),
            'end_date' => $today->copy()->endOfYear()->toDateString(),
            'is_active' => true,
        ]);
        $semester = Semester::query()->create([
            'academic_year_id' => $year->id,
            'name' => 'Ganjil',
            'start_date' => $today->copy()->startOfYear()->toDateString(),
            'end_date' => $today->copy()->endOfYear()->toDateString(),
            'is_active' => true,
        ]);
        $level = EducationLevel::query()->create([
            'name' => 'Kelas 1 SMA',
            'level_number' => 1,
            'jenjang' => 'SMA',
            'is_active' => true,
        ]);
        $class = SchoolClass::query()->create([
            'academic_year_id' => $year->id,
            'education_level_id' => $level->id,
            'name' => 'X-A',
            'code' => 'DASH-XA',
            'capacity' => 30,
            'is_active' => true,
        ]);
        $class->classStudents()->create([
            'student_id' => $lastMonthStudent->id,
            'academic_year_id' => $year->id,
            'status' => 'aktif',
        ]);

        $presentStatus = AttendanceStatus::query()->create(['code' => 'H', 'name' => 'Hadir', 'color' => '#22c55e']);
        $absentStatus = AttendanceStatus::query()->create(['code' => 'A', 'name' => 'Alpa', 'color' => '#ef4444']);
        foreach ([[$lastMonthStudent, $presentStatus], [$this->student('DASH003', $today->toDateString()), $absentStatus]] as [$student, $status]) {
            StudentAttendance::query()->create([
                'student_id' => $student->id,
                'school_class_id' => $class->id,
                'semester_id' => $semester->id,
                'date' => $today->toDateString(),
                'attendance_status_id' => $status->id,
                'status' => StudentAttendance::STATUS_VERIFIED,
            ]);
        }

        $invoiceStudent = $this->student('DASH004', $today->toDateString());
        FinanceInvoice::query()->create([
            'invoice_number' => 'INV-DASH-001',
            'customer_type' => 'student',
            'customer_id' => $invoiceStudent->id,
            'student_id' => $invoiceStudent->id,
            'issue_date' => $today->toDateString(),
            'total' => 500000,
            'paid_amount' => 0,
            'remaining_amount' => 500000,
            'status' => FinanceInvoice::STATUS_ISSUED,
        ]);
        FinancePayment::query()->create([
            'payment_number' => 'PAY-DASH-001',
            'customer_type' => 'student',
            'customer_id' => $invoiceStudent->id,
            'student_id' => $invoiceStudent->id,
            'amount' => 300000,
            'method' => FinancePayment::METHOD_CASH,
            'status' => FinancePayment::STATUS_PAID,
            'paid_at' => $today->copy()->setTime(9, 15),
        ]);

        $response = $this->getJson('/api/v1/dashboard/overview?days=7')->assertOk();
        $data = $response->json('data');

        $this->assertSame(7, $data['attendance_days']);
        $this->assertSame(4, $data['students']['active_total']);
        $this->assertSame(3, $data['students']['admitted_this_month']);
        $this->assertSame(1, $data['students']['admitted_last_month']);
        $this->assertCount(6, $data['students']['admissions_trend']);
        $currentAdmissionMonth = collect($data['students']['admissions_trend'])
            ->firstWhere('period', $today->format('Y-m'));
        $this->assertSame(3, $currentAdmissionMonth['count']);
        $this->assertSame(1, $data['academic']['active_classes']);
        $this->assertSame(1, $data['academic']['enrolled_students']);
        $this->assertSame(2, $data['attendance']['today']['total']);
        $this->assertCount(7, $data['attendance']['daily']);
        $this->assertCount(6, $data['finance']['series']);
        $this->assertEquals(50, $data['attendance']['today']['rate']);
        $this->assertEquals(300000, $data['finance']['received_this_month']);
        $this->assertEquals(500000, $data['finance']['billed_this_month']);
        $this->assertEquals(500000, $data['finance']['outstanding_total']);
    }

    public function test_data_setiap_domain_hanya_dikembalikan_kepada_pengguna_dengan_permission_baca(): void
    {
        $this->userWithPermissions('student.view');

        $data = $this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data');

        $this->assertArrayHasKey('students', $data);
        $this->assertArrayNotHasKey('academic', $data);
        $this->assertArrayNotHasKey('attendance', $data);
        $this->assertArrayNotHasKey('finance', $data);
    }

    public function test_rentang_grafik_di_luar_pilihan_kembali_ke_empat_belas_hari(): void
    {
        $this->userWithPermissions('academic.view');

        $data = $this->getJson('/api/v1/dashboard/overview?days=365')->assertOk()->json('data');

        $this->assertSame(14, $data['attendance_days']);
        $this->assertCount(14, $data['attendance']['daily']);
    }
}
