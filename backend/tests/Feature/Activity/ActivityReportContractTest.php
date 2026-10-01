<?php

namespace Tests\Feature\Activity;

use App\Modules\Activity\Application\Services\ActivityAnalyticsService;
use App\Modules\Activity\Application\Services\ActivityAttendanceService;
use App\Modules\Activity\Application\Services\ActivityRegistrationService;
use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivitySession;

/**
 * Kontrak laporan operasional.
 *
 * Kesalahan yang dijaga di sini pernah nyata: daftar `columns` sebuah laporan
 * tidak cocok dengan kunci baris yang dihasilkan, sehingga tabel tetap tampak
 * rapi tetapi seluruh angkanya kosong. Tes ini memastikan setiap kolom yang
 * dijanjikan benar-benar ada pada barisnya \\u2014 untuk semua jenis laporan.
 */
class ActivityReportContractTest extends ActivityTestCase
{
    protected function seededProgram(): void
    {
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $program = $this->makeProgram(['code' => 'REP-1', 'name' => 'Futsal Laporan', 'maximum_capacity' => 20]);
        $this->makeSchedule($program, ['day_of_week' => 1]);

        $student = $this->makeStudent('Nabila');
        app(ActivityRegistrationService::class)->start($manager, $program, (int) $student->id);

        app(ActivityScheduleService::class)->generateSessions($program, '2026-09-07', '2026-09-14', $manager);
        $session = ActivitySession::query()->where('program_id', $program->id)->orderBy('date')->firstOrFail();
        app(ActivityAttendanceService::class)->record($session, (int) $student->id, ['status' => 'PRESENT'], $manager);

        $this->assertSame(1, ActivityMembership::query()->where('program_id', $program->id)->count());
    }

    /** @return array<int, string> */
    protected function reportTypes(): array
    {
        $unknown = app(ActivityAnalyticsService::class)->report('tidak-ada');

        return $unknown['available'] ?? [];
    }

    public function test_daftar_jenis_laporan_yang_diumumkan_benar_benar_dapat_dihasilkan(): void
    {
        $this->seededProgram();

        $types = $this->reportTypes();

        $this->assertNotEmpty($types, 'Daftar jenis laporan yang tersedia tidak boleh kosong.');

        foreach ($types as $type) {
            $report = app(ActivityAnalyticsService::class)->report($type);

            $this->assertSame($type, $report['type'], "Laporan {$type} tidak dikenali padahal diumumkan tersedia.");
            $this->assertNotEmpty($report['columns'], "Laporan {$type} tidak mendeklarasikan kolom.");
        }
    }

    public function test_setiap_kolom_yang_dijanjikan_ada_pada_barisnya(): void
    {
        $this->seededProgram();

        $reportsWithRows = 0;

        foreach ($this->reportTypes() as $type) {
            $report = app(ActivityAnalyticsService::class)->report($type);

            foreach ($report['rows'] as $row) {
                $missing = array_values(array_diff($report['columns'], array_keys($row)));

                $this->assertSame(
                    [],
                    $missing,
                    "Laporan {$type} menjanjikan kolom ".implode(', ', $missing).' tetapi barisnya tidak memuatnya.',
                );

                $reportsWithRows++;
            }
        }

        $this->assertGreaterThan(0, $reportsWithRows, 'Data uji seharusnya menghasilkan setidaknya satu baris laporan.');
    }

    public function test_laporan_keanggotaan_memecah_status_menjadi_kolom_angka(): void
    {
        $this->seededProgram();

        $report = app(ActivityAnalyticsService::class)->report('membership');

        $this->assertSame(
            ['activity', 'active', 'suspended', 'inactive', 'completed', 'withdrawn'],
            $report['columns'],
        );

        $row = $report['rows'][0];

        $this->assertSame('Futsal Laporan', $row['activity']);
        $this->assertSame(1, $row['active']);
        $this->assertSame(0, $row['withdrawn']);
    }

    public function test_laporan_dapat_disaring_per_program(): void
    {
        $this->seededProgram();
        $manager = $this->actingAsRole('ACTIVITY_MANAGER');
        $lain = $this->makeProgram(['code' => 'REP-2', 'name' => 'Basket Laporan']);

        $program = \App\Modules\Activity\Models\ActivityProgram::query()->where('code', 'REP-1')->firstOrFail();

        $scoped = app(ActivityAnalyticsService::class)->report('participation', ['program_id' => (int) $program->id]);
        $this->assertCount(1, $scoped['rows']);
        $this->assertSame('Futsal Laporan', $scoped['rows'][0]['activity']);

        $all = app(ActivityAnalyticsService::class)->report('participation');
        $this->assertGreaterThanOrEqual(2, count($all['rows']));

        $this->assertNotNull($lain->id);
        $this->assertNotNull($manager->id);
    }
}
