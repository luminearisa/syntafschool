<?php

namespace Tests\Feature\Religious;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\SchoolOperations\Religious\Application\Services\ReligiousDashboardService;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\ParentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReligiousParentGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_wali_mendapatkan_anak_dari_relasional_guardian_yang_benar(): void
    {
        $guardian = Guardian::query()->create(['full_name' => 'Wali Uji']);
        $otherGuardian = Guardian::query()->create(['full_name' => 'Wali Lain']);
        $student = Student::query()->create([
            'nis' => 'REL-PARENT-001',
            'full_name' => 'Siswa Uji',
            'is_active' => true,
        ]);
        $otherStudent = Student::query()->create([
            'nis' => 'REL-PARENT-002',
            'full_name' => 'Siswa Lain',
            'is_active' => true,
        ]);

        DB::table('student_guardians')->insert([
            ['student_id' => $student->id, 'guardian_id' => $guardian->id, 'is_primary' => true],
            ['student_id' => $otherStudent->id, 'guardian_id' => $otherGuardian->id, 'is_primary' => true],
        ]);

        $user = User::factory()->create([
            'userable_type' => $guardian->getMorphClass(),
            'userable_id' => $guardian->id,
        ]);

        $gateway = app(ParentGatewayInterface::class);

        $this->assertSame([['id' => $student->id]], $gateway->childrenFor((int) $user->id));
        $this->assertTrue($gateway->canAccess((int) $user->id, (int) $student->id));
        $this->assertFalse($gateway->canAccess((int) $user->id, (int) $otherStudent->id));

        $dashboard = app(ReligiousDashboardService::class)->parent((int) $user->id, '2026-06-08');
        $this->assertSame([$student->id], array_column($dashboard['children'], 'student_id'));
    }
}
