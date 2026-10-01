<?php

namespace App\Modules\Activity\Database\Seeders;

use App\Modules\Activity\Domain\Enums\MembershipRole;
use App\Modules\Activity\Models\ActivityObjective;
use App\Modules\Activity\Models\ActivityRole;
use App\Modules\Activity\Models\ActivitySkill;
use App\Modules\Activity\Models\ActivityCategory;
use App\Modules\Activity\Models\ActivityPeriod;
use Illuminate\Database\Seeder;

/**
 * Master dasar aktivitas: kategori berjenjang, tujuan perkembangan,
 * keterampilan, dan peran anggota.
 *
 * Semua ini **data**, bukan kode: sekolah menambah "Kepesantrenan" atau
 * keterampilan "Khotbah" lewat layar pengaturan tanpa perlu deploy.
 */
class ActivityMasterSeeder extends Seeder
{
    /** Kategori berjenjang sesuai contoh pada spesifikasi (\§15, \§1). */
    protected array $categories = [
        'SPORT' => ['Olahraga', ['Indoor', 'Outdoor']],
        'ART' => ['Seni', ['Musik', 'Seni Visual']],
        'ACADEMIC' => ['Akademik', ['Sains', 'Teknologi']],
        'RELIGIOUS' => ['Keislaman', ['Tahfidz', 'Dakwah']],
        'ORGANIZATION' => ['Organisasi', ['Kepemimpinan', 'Kesiswaan']],
        'COMMUNITY' => ['Pengabdian Masyarakat', ['Sosial', 'Relawan']],
    ];

    /** @var list<array{string, string, string}> */
    protected array $skills = [
        ['LEADERSHIP', 'Kepemimpinan', 'LEADERSHIP'],
        ['COMMUNICATION', 'Komunikasi', 'SOCIAL'],
        ['TEAMWORK', 'Kerja Sama', 'SOCIAL'],
        ['DISCIPLINE', 'Kedisiplinan', 'CHARACTER'],
        ['SPORTSMANSHIP', 'Sportivitas', 'CHARACTER'],
        ['PUBLIC_SPEAKING', 'Berbicara di Depan Umum', 'COMMUNICATION'],
        ['PROGRAMMING', 'Pemrograman', 'TECHNOLOGY'],
        ['PROBLEM_SOLVING', 'Pemecahan Masalah', 'COGNITIVE'],
        ['CREATIVE_WRITING', 'Menulis Kreatif', 'ART'],
        ['CREATIVITY', 'Kreativitas', 'ART'],
        ['PHYSICAL_SKILL', 'Keterampilan Fisik', 'SPORT'],
        ['SOCIAL_SERVICE', 'Kepedulian Sosial', 'SOCIAL'],
    ];

    /** @var list<array{string, string, string}> */
    protected array $objectives = [
        ['TEAMWORK_DEV', 'Membangun kerja sama tim', 'DEVELOPMENT'],
        ['DISCIPLINE_DEV', 'Menumbuhkan kedisiplinan', 'DEVELOPMENT'],
        ['PHYSICAL_DEV', 'Meningkatkan kebugaran dan keterampilan fisik', 'DEVELOPMENT'],
        ['ALGORITHM_DEV', 'Menguasai dasar algoritma', 'COMPETENCY'],
        ['PROBLEM_SOLVING_DEV', 'Melatih pemecahan masalah', 'COMPETENCY'],
        ['SOFTWARE_DEV', 'Mengembangkan perangkat lunak sederhana', 'COMPETENCY'],
        ['LEADERSHIP_DEV', 'Melatih kepemimpinan dan pengambilan keputusan', 'DEVELOPMENT'],
        ['PUBLIC_SPEAKING_DEV', 'Melatih keberanian berbicara di depan umum', 'COMPETENCY'],
        ['CHARACTER_DEV', 'Membentuk karakter dan akhlak', 'DEVELOPMENT'],
    ];

    public function run(): void
    {
        foreach ($this->categories as $code => [$name, $children]) {
            $parent = ActivityCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'status' => 'ACTIVE', 'sort_order' => 0],
            );

            foreach ($children as $index => $childName) {
                $childCode = $code.'_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $childName));

                ActivityCategory::query()->updateOrCreate(
                    ['code' => $childCode],
                    [
                        'name' => $childName,
                        'parent_id' => $parent->id,
                        'status' => 'ACTIVE',
                        'sort_order' => $index + 1,
                    ],
                );
            }
        }

        foreach ($this->skills as [$code, $name, $category]) {
            ActivitySkill::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'category' => $category, 'is_active' => true],
            );
        }

        foreach ($this->objectives as [$code, $name, $category]) {
            ActivityObjective::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'category' => $category, 'is_active' => true],
            );
        }

        foreach (MembershipRole::cases() as $index => $role) {
            ActivityRole::query()->updateOrCreate(
                ['code' => $role->value],
                [
                    'name' => $role->label(),
                    'can_lead' => in_array($role->value, ['CAPTAIN', 'VICE_CAPTAIN', 'TEAM_LEADER', 'MENTOR', 'PEER_MENTOR'], true),
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }

        // Periode berjalan: diturunkan dari tahun ajaran aktif bila tersedia.
        $year = \App\Models\AcademicYear::query()->where('is_active', true)->first();
        $semester = \App\Models\Semester::query()->where('is_active', true)->first();

        ActivityPeriod::query()->updateOrCreate(
            ['code' => (string) ($semester?->name ?? $year?->name ?? 'DEFAULT')],
            [
                'name' => trim(($year?->name ?? 'Tahun Ajaran Berjalan').' '.($semester?->name ?? '')),
                'type' => $semester === null ? 'ACADEMIC_YEAR' : 'SEMESTER',
                'academic_year_id' => $year?->id,
                'semester_id' => $semester?->id,
                'start_date' => $semester?->start_date?->toDateString() ?? $year?->start_date?->toDateString() ?? now()->startOfYear()->toDateString(),
                'end_date' => $semester?->end_date?->toDateString() ?? $year?->end_date?->toDateString() ?? now()->endOfYear()->toDateString(),
                'status' => 'ACTIVE',
                'is_current' => true,
            ],
        );
    }
}
