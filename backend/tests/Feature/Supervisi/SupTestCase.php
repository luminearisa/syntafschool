<?php

namespace Tests\Feature\Supervisi;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Database\Seeders\SupGradeScaleSeeder;
use App\Modules\Supervisi\Database\Seeders\SupPermissionSeeder;
use App\Modules\Supervisi\Models\SupSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dasar pengujian modul supervisi.
 *
 * Menyediakan peran ERP, permission `supervisi.*`, skala predikat, dan helper
 * pegawai + akun sehingga setiap berkas tes cukup menulis skenario yang diuji.
 */
abstract class SupTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sengaja tidak memakai `RolePermissionSeeder` penuh (ratusan permission
        // lintas modul) agar pengujian supervisi tetap cepat: cukup peran yang
        // dipakai tata kelola supervisi + permission `supervisi.*`.
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seed(SupPermissionSeeder::class);
        $this->seed(SupGradeScaleSeeder::class);
    }

    /** Peran ERP yang relevan bagi tata kelola supervisi. */
    protected const ROLES = [
        'super-admin', 'admin-sekolah', 'kepala-sekolah', 'kepala-unit', 'auditor',
        'guru', 'staf', 'wali-kelas', 'bendahara',
    ];

    /** Akun ERP dengan peran tertentu, langsung terautentikasi. */
    protected function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** Data pegawai (tanpa akun). */
    protected function employee(array $attributes = []): Employee
    {
        return Employee::query()->create([
            'full_name' => $attributes['full_name'] ?? 'Pegawai '.fake()->unique()->numerify('###'),
            'position' => $attributes['position'] ?? 'Guru Mata Pelajaran',
            'employee_type' => $attributes['employee_type'] ?? 'guru',
            'employment_status' => 'Honorer',
            'is_teacher' => ($attributes['employee_type'] ?? 'guru') === 'guru',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /** Pegawai yang sekaligus memiliki akun dengan peran tertentu. */
    protected function employeeWithAccount(Employee $employee, string $role = 'guru'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['userable_type' => Employee::class, 'userable_id' => $employee->id])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /** Autentikasi sebagai akun tertentu (mengembalikan test case agar mudah dirantai). */
    protected function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /** Daftarkan pegawai sebagai supervisor lewat service (aturan produksi). */
    protected function registerSupervisor(array $attributes = []): SupSupervisor
    {
        $employee = $attributes['employee'] ?? $this->employee();

        return app(\App\Modules\Supervisi\Application\Services\SupervisorDirectoryService::class)
            ->register($employee, $attributes + [
                'kind' => 'KEPALA_SEKOLAH',
                'can_finalize' => true,
            ]);
    }

    /**
     * Instrumen kecil siap pakai (terbit) untuk pengujian pelaksanaan.
     *
     * Susunannya sengaja mencampur tipe input agar sekaligus menguji mesin
     * skor: satu indikator skala rubrik, satu entri angka, satu ya/tidak,
     * dan satu narasi.
     *
     * @param  array<string,mixed>  $overrides
     * @return array{instrument: \App\Modules\Supervisi\Models\SupInstrument, version: \App\Modules\Supervisi\Models\SupInstrumentVersion}
     */
    protected function publishedInstrument(array $overrides = []): array
    {
        $builder = app(\App\Modules\Supervisi\Application\Services\InstrumentBuilderService::class);
        $user = User::factory()->create(['is_active' => true]);

        $instrument = $builder->createInstrument([
            'name' => $overrides['name'] ?? 'Instrumen Uji Supervisi',
            'category' => $overrides['category'] ?? 'AKADEMIK',
            'target_type' => $overrides['target_type'] ?? 'GURU',
            'scoring_mode' => $overrides['scoring_mode'] ?? 'PERCENT',
        ], $user);

        $version = $builder->createVersion($instrument, [], $user);

        $sections = $overrides['sections'] ?? [[
            'title' => 'Pelaksanaan Pembelajaran',
            'weight' => 1,
            'indicators' => [
                [
                    'statement' => 'Menyampaikan tujuan pembelajaran dengan jelas',
                    'guidance' => 'Tujuan disampaikan lisan/tertulis dan dipahami peserta didik.',
                    'input_type' => 'SCALE',
                    'weight' => 1,
                    'is_required' => true,
                    'min_comment_below' => 2,
                    'options' => [
                        ['label' => 'Kurang', 'score' => 1, 'tone' => 'RED'],
                        ['label' => 'Cukup', 'score' => 2, 'tone' => 'AMBER'],
                        ['label' => 'Baik', 'score' => 3, 'tone' => 'GREEN'],
                        ['label' => 'Sangat Baik', 'score' => 4, 'tone' => 'GREEN'],
                    ],
                ],
                [
                    'statement' => 'Jumlah peserta didik yang terlibat aktif (orang)',
                    'input_type' => 'NUMBER',
                    'weight' => 1,
                    'score_min' => 0,
                    'score_max' => 10,
                    'score_step' => 1,
                    'score_unit' => 'orang',
                    'direction' => 'HIGHER_BETTER',
                ],
                [
                    'statement' => 'Guru menutup pembelajaran dengan refleksi',
                    'input_type' => 'BOOLEAN',
                    'weight' => 1,
                    'score_yes' => 1,
                    'score_no' => 0,
                    'score_partial' => 0.5,
                ],
                [
                    'statement' => 'Catatan fakta observasi',
                    'input_type' => 'LONG_TEXT',
                    'weight' => 1,
                    'is_required' => false,
                ],
            ],
        ]];

        $version = $builder->syncStructure($version, $sections, $user);
        $version = $builder->publish($version, $user);

        return ['instrument' => $instrument->refresh(), 'version' => $version];
    }

    /** Kegiatan supervisi dengan seluruh relasi yang dibutuhkan presenter. */
    protected function visitWithRelations(\App\Modules\Supervisi\Models\SupVisit $visit): \App\Modules\Supervisi\Models\SupVisit
    {
        return $visit->load([
            'answers', 'scores', 'conferences', 'findings', 'evidence',
            'acknowledgments', 'disputes', 'revisions',
            'instrumentVersion.sections.indicators.options',
            'instrumentVersion.gradeScale',
        ])->loadCount(['answers', 'findings']);
    }
}
