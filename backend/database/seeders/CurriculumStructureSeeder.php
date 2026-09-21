<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumStructure;
use App\Models\CurriculumStructureItem;
use App\Models\EducationLevel;
use App\Models\Subject;
use App\Models\SubjectGroup;
use App\Models\SubjectPrerequisite;
use Illuminate\Database\Seeder;

/**
 * Struktur Kurikulum Merdeka SMA tingkat X, XI, dan XII.
 *
 * Isi disusun dari 23 mapel yang sudah ada di tabel `subjects` (lihat
 * `SubjectSeeder`) — seeder ini tidak mengarang mapel baru, hanya memetakan
 * kode mapel yang tersedia ke alokasi JP per semester.
 *
 * Idempoten: struktur di-`firstOrCreate` per (kurikulum, jenjang, versi) dan
 * setiap item di-`updateOrCreate` per (struktur, mapel, semester).
 */
class CurriculumStructureSeeder extends Seeder
{
    /**
     * Mapel wajib yang muncul di semua tingkat, kedua semester.
     *
     * @var list<array{code: string, group: string, hours: int}>
     */
    protected array $wajib = [
        ['code' => 'PABP', 'group' => 'WAJIB', 'hours' => 3],
        ['code' => 'PKN', 'group' => 'WAJIB', 'hours' => 2],
        ['code' => 'BIND', 'group' => 'WAJIB', 'hours' => 4],
        ['code' => 'MTK', 'group' => 'WAJIB', 'hours' => 4],
        ['code' => 'BING', 'group' => 'WAJIB', 'hours' => 3],
        ['code' => 'SEJIND', 'group' => 'WAJIB', 'hours' => 2],
        ['code' => 'PJOK', 'group' => 'WAJIB', 'hours' => 3],
        ['code' => 'SENBUD', 'group' => 'WAJIB', 'hours' => 2],
        ['code' => 'PKWU', 'group' => 'WAJIB', 'hours' => 2],
        ['code' => 'INF', 'group' => 'WAJIB', 'hours' => 2],
    ];

    /**
     * Tambahan per tingkat (jenjang SMA, kelas 10–12).
     *
     * Kunci array memakai `level_number` pada `education_levels` untuk jenjang
     * SMA (1 = Kelas X, 2 = Kelas XI, 3 = Kelas XII), karena nama tingkat di
     * repo ini adalah "Kelas 1"…"Kelas 3" per jenjang.
     *
     * @var array<int, list<array{code: string, group: string, hours: int}>>
     */
    protected array $perLevel = [
        1 => [
            ['code' => 'FIS', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'KIM', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'BIO', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'BJWA', 'group' => 'MULOK', 'hours' => 2],
        ],
        2 => [
            ['code' => 'FIS', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'KIM', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'BIO', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'MTKL', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'BJWA', 'group' => 'MULOK', 'hours' => 2],
        ],
        3 => [
            ['code' => 'FIS', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'KIM', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'BIO', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'MTKL', 'group' => 'MIPA', 'hours' => 3],
            ['code' => 'BJWA', 'group' => 'MULOK', 'hours' => 2],
        ],
    ];

    /**
     * Prasyarat mapel yang masuk akal (mapel => prasyarat).
     *
     * @var array<string, list<string>>
     */
    protected array $prerequisites = [
        'FIS' => ['MTK'],
        'KIM' => ['MTK'],
        'MTKL' => ['MTK'],
        'BINDLAN' => ['BIND'],
        'BINGLAN' => ['BING'],
        'SEJLAN' => ['SEJIND'],
    ];

    public function run(): void
    {
        $curriculum = Curriculum::where('code', 'MERDEKA')->first()
            ?? Curriculum::where('is_active', true)->first();

        if (! $curriculum) {
            return;
        }

        $academicYear = AcademicYear::where('is_active', true)->first();
        $levelLabels = [1 => 'X', 2 => 'XI', 3 => 'XII'];

        foreach ($levelLabels as $levelNumber => $label) {
            $level = EducationLevel::where('jenjang', 'SMA')
                ->where('level_number', $levelNumber)
                ->first();

            if (! $level) {
                continue;
            }

            $structure = CurriculumStructure::firstOrCreate(
                [
                    'curriculum_id' => $curriculum->id,
                    'education_level_id' => $level->id,
                    'version' => 1,
                ],
                [
                    'academic_year_id' => $academicYear?->id,
                    'name' => "Struktur Kurikulum Merdeka SMA Kelas {$label}",
                    'is_active' => true,
                    'notes' => 'Alokasi JP per pekan. Disusun dari katalog mapel Kurikulum Merdeka.',
                ],
            );

            $items = array_merge($this->wajib, $this->perLevel[$levelNumber] ?? []);

            foreach ([1, 2] as $semester) {
                foreach (array_values($items) as $index => $item) {
                    $subjectId = $this->subjectId($item['code']);

                    if (! $subjectId) {
                        continue;
                    }

                    CurriculumStructureItem::updateOrCreate(
                        [
                            'curriculum_structure_id' => $structure->id,
                            'subject_id' => $subjectId,
                            'semester' => $semester,
                        ],
                        [
                            'subject_group_id' => $this->subjectGroupId($item['group']),
                            'weekly_hours' => $item['hours'],
                            'is_required' => true,
                            'sort_order' => $index + 1,
                            'notes' => null,
                        ],
                    );
                }
            }
        }

        $this->seedPrerequisites();
    }

    protected function seedPrerequisites(): void
    {
        foreach ($this->prerequisites as $subjectCode => $prerequisiteCodes) {
            $subjectId = $this->subjectId($subjectCode);

            if (! $subjectId) {
                continue;
            }

            foreach ($prerequisiteCodes as $prerequisiteCode) {
                $prerequisiteId = $this->subjectId($prerequisiteCode);

                if (! $prerequisiteId || $prerequisiteId === $subjectId) {
                    continue;
                }

                SubjectPrerequisite::firstOrCreate(
                    [
                        'subject_id' => $subjectId,
                        'prerequisite_subject_id' => $prerequisiteId,
                    ],
                    ['notes' => 'Prasyarat dari seeder Kurikulum Merdeka.'],
                );
            }
        }
    }

    protected function subjectId(string $code): ?int
    {
        return Subject::where('code', $code)->value('id');
    }

    protected function subjectGroupId(string $code): ?int
    {
        return SubjectGroup::where('code', $code)->value('id');
    }
}
