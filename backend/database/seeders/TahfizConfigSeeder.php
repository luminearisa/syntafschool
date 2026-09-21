<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\TahfizInstructor;
use App\Models\TahfizLevel;
use App\Models\TahfizMistakeType;
use App\Models\TahfizMutabaahItem;
use App\Models\TahfizProgram;
use App\Models\TahfizRubric;
use Illuminate\Database\Seeder;

/**
 * Konfigurasi awal modul Tahfiz.
 *
 * Semua aturan di bawah adalah DATA, bukan kode: program, level, rubrik,
 * taksonomi kesalahan, dan item mutabaah dapat diubah admin melalui API.
 * Seeder ini hanya memberi titik mulai yang masuk akal.
 */
class TahfizConfigSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPrograms();
        $this->seedLevels();
        $this->seedRubrics();
        $this->seedMistakeTypes();
        $this->seedMutabaahItems();
        $this->seedInstructors();
    }

    private function seedPrograms(): void
    {
        $programs = [
            ['Tahfiz Reguler', 'THZ-REG', 'student', 'none', null, 'program'],
            ['Tahfiz Intensif', 'THZ-INT', 'student', 'juz', '30', 'program'],
            ['Tahfiz Dewasa', 'THZ-DWS', 'public', 'juz', '30', 'program'],
            ['Tahfiz Karyawan', 'THZ-KRY', 'employee', 'juz', '5', 'program'],
            ['Tahfiz Anak', 'THZ-ANK', 'student', 'juz', '30', 'program'],
            ['Tahfiz Weekend', 'THZ-WKD', 'public', 'juz', '5', 'program'],
            ['Tahsin & Tahfiz', 'THS-TFZ', 'public', 'none', null, 'program'],
            ['Program 1 Juz', 'PRG-1JZ', null, 'juz', '1', 'program'],
            ['Program 5 Juz', 'PRG-5JZ', null, 'juz', '5', 'program'],
            ['Program 10 Juz', 'PRG-10JZ', null, 'juz', '10', 'program'],
            ['Program 30 Juz', 'PRG-30JZ', null, 'juz', '30', 'program'],
        ];

        foreach ($programs as [$name, $code, $type, $targetType, $targetValue, $unit]) {
            TahfizProgram::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'description' => "Program {$name} — konfigurasi dapat diubah admin.",
                    'participant_type' => $type,
                    'duration_value' => 12,
                    'duration_unit' => 'month',
                    'target_type' => $targetType,
                    'target_value' => $targetValue,
                    'minimum_attendance' => 80,
                    'assessment_scheme' => ['setoran' => 40, 'murojaah' => 20, 'tahsin' => 15, 'attendance' => 10, 'exam' => 15],
                    'requires_placement_test' => in_array($code, ['THZ-INT', 'THZ-DWS', 'THZ-KRY', 'PRG-30JZ']),
                    'self_registration' => in_array($code, ['THZ-DWS', 'THZ-WKD', 'THS-TFZ']),
                    'status' => TahfizProgram::STATUS_ACTIVE,
                ]
            );
        }

        $this->command?->info('TahfizProgram: '.count($programs).' program.');
    }

    private function seedLevels(): void
    {
        $program = TahfizProgram::query()->where('code', 'THZ-REG')->first();

        $levels = [
            ['Level 1 — Pemula', 'LVL-1', 1, null, null],
            ['Level 2 — Tahsin', 'LVL-2', 2, null, null],
            ['Level 3 — Juz 30', 'LVL-3', 3, 30, 30],
            ['Level 4 — Juz 29', 'LVL-4', 4, 29, 29],
            ['Level 5 — Juz 1-5', 'LVL-5', 5, 1, 5],
            ['Level 6 — Juz 6-10', 'LVL-6', 6, 6, 10],
            ['Level 7 — Juz 11-20', 'LVL-7', 7, 11, 20],
            ['Level 8 — Juz 21-30', 'LVL-8', 8, 21, 30],
        ];

        foreach ($levels as $index => [$name, $code, $order, $juzStart, $juzEnd]) {
            TahfizLevel::firstOrCreate(
                ['code' => $code],
                [
                    'program_id' => $program?->id,
                    'name' => $name,
                    'order' => $order,
                    'juz_start' => $juzStart,
                    'juz_end' => $juzEnd,
                    'description' => "Tingkat penguasaan {$name}.",
                    'status' => TahfizLevel::STATUS_ACTIVE,
                ]
            );
        }

        $this->command?->info('TahfizLevel: '.count($levels).' level.');
    }

    private function seedRubrics(): void
    {
        $rubrics = [
            ['Rubrik Tahsin', TahfizRubric::SCOPE_TAHSIN, [
                'Makhraj', 'Sifat Huruf', 'Tajwid', 'Mad', 'Qalqalah', 'Ghunnah',
                'Waqaf & Ibtida', 'Kelancaran', 'Tartil',
            ]],
            ['Rubrik Tilawah', TahfizRubric::SCOPE_TILAWAH, [
                'Makhraj', 'Tajwid', 'Kelancaran', 'Tartil',
            ]],
            ['Rubrik Hafalan', TahfizRubric::SCOPE_HAFALAN, [
                'Kelancaran', 'Mutqin', 'Sambung Ayat', 'Tajwid', 'Makhraj',
            ]],
            ['Rubrik Placement Test', TahfizRubric::SCOPE_PLACEMENT, [
                'Tilawah — Makhraj', 'Tilawah — Tajwid', 'Tilawah — Kelancaran', 'Tilawah — Tartil',
                'Hafalan — Jumlah', 'Hafalan — Kelancaran', 'Hafalan — Mutqin',
                'Tahsin — Makhraj', 'Tahsin — Sifat Huruf', 'Tahsin — Mad & Ghunnah',
            ]],
            ['Rubrik Ujian', TahfizRubric::SCOPE_EXAM, [
                'Hafalan', 'Kelancaran', 'Sambung Ayat', 'Tajwid', 'Makhraj',
            ]],
            ['Rubrik Tasmi', TahfizRubric::SCOPE_TASMI, [
                'Kelancaran', 'Mutqin', 'Tajwid', 'Makhraj', 'Waqaf',
            ]],
        ];

        $descriptions = [
            1 => 'Belum menguasai',
            2 => 'Perlu perbaikan',
            3 => 'Cukup',
            4 => 'Baik',
            5 => 'Sangat baik',
        ];

        foreach ($rubrics as [$name, $scope, $criteria]) {
            $rubric = TahfizRubric::firstOrCreate(
                ['name' => $name],
                [
                    'scope' => $scope,
                    'description' => "Rubrik {$name} — deskripsi tiap tingkat dapat diubah admin.",
                    'max_score' => 5,
                    'status' => TahfizRubric::STATUS_ACTIVE,
                ]
            );

            foreach ($criteria as $index => $criterion) {
                $rubric->items()->firstOrCreate(
                    ['criterion' => $criterion],
                    [
                        'weight' => 1,
                        'order' => $index + 1,
                        'level_descriptions' => $descriptions,
                    ]
                );
            }
        }

        $this->command?->info('TahfizRubric: '.count($rubrics).' rubrik.');
    }

    private function seedMistakeTypes(): void
    {
        $types = [
            ['lupa_ayat', 'Lupa ayat', 'memorization', 1.5],
            ['salah_sambung', 'Salah sambung ayat', 'memorization', 1.2],
            ['salah_lafadz', 'Salah lafadz', 'pronunciation', 1.0],
            ['salah_harakat', 'Salah harakat', 'pronunciation', 0.8],
            ['tajwid', 'Kesalahan tajwid', 'tajwid', 1.0],
            ['makhraj', 'Kesalahan makhraj', 'pronunciation', 1.0],
            ['waqaf', 'Salah waqaf', 'reading', 0.7],
            ['mad', 'Kesalahan mad', 'tajwid', 0.8],
            ['ghunnah', 'Kesalahan ghunnah', 'tajwid', 0.7],
            ['qalqalah', 'Kesalahan qalqalah', 'tajwid', 0.7],
            ['ulang_ayat', 'Mengulang ayat', 'memorization', 0.5],
            ['baca_lambat', 'Membaca terlalu lambat', 'reading', 0.5],
        ];

        foreach ($types as [$code, $label, $category, $weight]) {
            TahfizMistakeType::firstOrCreate(
                ['code' => $code],
                [
                    'label' => $label,
                    'category' => $category,
                    'severity_weight' => $weight,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info('TahfizMistakeType: '.count($types).' jenis kesalahan.');
    }

    private function seedMutabaahItems(): void
    {
        $items = [
            ['tilawah', 'Tilawah Al-Quran', 'quran', 'count', 'halaman', 1],
            ['hafalan_baru', 'Hafalan Baru', 'quran', 'count', 'halaman', 1],
            ['murojaah', 'Murojaah', 'quran', 'boolean', null, 1],
            ['tasmi', 'Tasmi\'', 'quran', 'boolean', null, 2],
            ['tahsin', 'Latihan Tahsin', 'quran', 'boolean', null, 2],
            ['shalat_berjamaah', 'Shalat Berjamaah', 'worship', 'boolean', null, 3],
            ['shalat_dhuha', 'Shalat Dhuha', 'worship', 'boolean', null, 4],
            ['dzikir_pagi', 'Dzikir Pagi', 'worship', 'boolean', null, 5],
            ['dzikir_sore', 'Dzikir Sore', 'worship', 'boolean', null, 6],
            ['belajar', 'Belajar / Tugas Sekolah', 'activity', 'boolean', null, 7],
            ['olahraga', 'Olahraga', 'activity', 'boolean', null, 8],
            ['catatan_harian', 'Catatan Harian', 'other', 'boolean', null, 9],
        ];

        foreach ($items as [$code, $label, $category, $valueType, $unit, $order]) {
            TahfizMutabaahItem::firstOrCreate(
                ['code' => $code],
                [
                    'label' => $label,
                    'category' => $category,
                    'value_type' => $valueType,
                    'unit' => $unit,
                    'target_value' => $valueType === 'count' ? 1 : null,
                    'order' => $order,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info('TahfizMutabaahItem: '.count($items).' item.');
    }

    private function seedInstructors(): void
    {
        // Beberapa pegawai (guru) ditetapkan sebagai instruktur/musyrif Tahfiz.
        $teachers = Employee::query()->where('is_teacher', true)->orderBy('id')->limit(6)->get();
        if ($teachers->isEmpty()) {
            $this->command?->warn('TahfizInstructor dilewati — belum ada pegawai.');

            return;
        }

        $roles = [
            TahfizInstructor::ROLE_INSTRUCTOR,
            TahfizInstructor::ROLE_INSTRUCTOR,
            TahfizInstructor::ROLE_ASSISTANT,
            TahfizInstructor::ROLE_MUSYRIF,
            TahfizInstructor::ROLE_MUSYRIFAH,
            TahfizInstructor::ROLE_MUSYRIF,
        ];

        foreach ($teachers as $index => $teacher) {
            TahfizInstructor::firstOrCreate(
                ['employee_id' => $teacher->id, 'role' => $roles[$index]],
                [
                    'specialization' => ['tahfiz', 'tahsin'],
                    'certificate' => 'Sertifikat Tahfiz — '.($index + 1),
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info('TahfizInstructor: '.$teachers->count().' instruktur.');
    }
}
