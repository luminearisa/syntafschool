<?php

namespace Tests\Feature;

use App\Models\AttendanceStatus;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAttendancePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_petugas_kepegawaian_dapat_membaca_lookup_dan_mencatat_absensi_tanpa_master_data_view(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['employee.view', 'employee.create']);
        Sanctum::actingAs($user);

        $status = AttendanceStatus::create(['code' => 'H', 'name' => 'Hadir', 'color' => '#16a34a']);
        LeaveType::create(['name' => 'Izin Keluarga', 'requires_attachment' => false]);
        $employee = Employee::create([
            'full_name' => 'Pegawai Tes',
            'employee_type' => 'staf',
            'employment_status' => 'PNS',
            'is_teacher' => false,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/attendance-lookups')
            ->assertOk()
            ->assertJsonPath('data.statuses.0.code', 'H')
            ->assertJsonPath('data.leave_types.0.name', 'Izin Keluarga');

        $this->postJson('/api/v1/employee-attendances', [
            'employee_id' => $employee->id,
            'date' => '2026-09-28',
            'attendance_status_id' => $status->id,
            'check_in' => '07:30',
        ])->assertCreated()
            ->assertJsonPath('data.employee.full_name', 'Pegawai Tes');

        $this->getJson('/api/v1/employee-attendances?from=2026-09-28&to=2026-09-28')
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $employee->id);
    }
}
