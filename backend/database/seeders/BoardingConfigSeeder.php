<?php

namespace Database\Seeders;

use App\Models\BoardingActivity;
use App\Models\BoardingActivityAttendanceType;
use App\Models\BoardingAttendanceType;
use App\Models\BoardingBed;
use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingFloor;
use App\Models\BoardingMusyrif;
use App\Models\BoardingRoom;
use App\Models\BoardingViolationType;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Konfigurasi dasar modul Boarding/Pesantren.
 *
 * Isi seeder ini adalah titik awal, bukan satu-satunya sumber kebenaran: semua
 * barisnya bisa disunting petugas lewat endpoint ADMIN — KONFIGURASI asrama.
 * Karena itu penulisannya `firstOrCreate` pada kunci unik tabel, supaya
 * dijalankan berulang tetap aman dan tidak menimpa hasil kerja petugas.
 *
 * Yang dibuat:
 * - master tipe absensi asrama (§17) — configurable, bukan hard-code;
 * - master jenis pelanggaran + bobot poin (§35, §36);
 * - master kegiatan harian (§16) beserta tipe absensi yang sah per kegiatan
 *   (§18: Shalat ≠ Halaqah ≠ Makan);
 * - struktur fisik demo: 2 asrama (putra & putri) x 1 gedung x 2 lantai x 2
 *   kamar x 8 tempat tidur (§5);
 * - satu penugasan kepala asrama bila ada karyawan (§8).
 *
 * Tabel transaksi (penempatan, absensi, izin, pelanggaran) TIDAK diisi di sini:
 * modul ini harus bisa hidup tanpa data contoh yang mengacaukan laporan, dan
 * transaksi dibuat oleh test/service memakai `Model::create`.
 */
class BoardingConfigSeeder extends Seeder
{
    /** Tipe absensi dasar kegiatan yang tidak disebut eksplisit di spesifikasi. */
    protected const BASE_ATTENDANCE_CODES = ['HADIR', 'IZIN', 'SAKIT', 'ALPHA'];

    public function run(): void
    {
        $attendanceTypes = $this->attendanceTypes();
        $this->violationTypes();
        $activities = $this->activities($attendanceTypes);

        $this->activityAttendanceTypes($activities, $attendanceTypes);
        $this->dormitories();
        $this->musyrifAssignment();
    }

    /**
     * Master tipe absensi (§17). `is_present` yang menentukan rekap, `category`
     * hanya pengelompokan laporan, dan `color` dipakai badge frontend.
     *
     * @return array<string, BoardingAttendanceType>
     */
    protected function attendanceTypes(): array
    {
        $definitions = [
            ['code' => 'HADIR', 'name' => 'Hadir', 'category' => 'present', 'is_present' => true, 'is_excused' => false, 'requires_note' => false, 'color' => '#16A34A'],
            ['code' => 'TIDAK-HADIR', 'name' => 'Tidak Hadir', 'category' => 'unexcused', 'is_present' => false, 'is_excused' => false, 'requires_note' => false, 'color' => '#EF4444'],
            ['code' => 'SAKIT', 'name' => 'Sakit', 'category' => 'excused', 'is_present' => false, 'is_excused' => true, 'requires_note' => true, 'color' => '#F59E0B'],
            ['code' => 'IZIN', 'name' => 'Izin', 'category' => 'excused', 'is_present' => false, 'is_excused' => true, 'requires_note' => true, 'color' => '#0EA5E9'],
            ['code' => 'ALPHA', 'name' => 'Alpha (Tanpa Keterangan)', 'category' => 'unexcused', 'is_present' => false, 'is_excused' => false, 'requires_note' => false, 'color' => '#B91C1C'],
            ['code' => 'TERLAMBAT', 'name' => 'Terlambat', 'category' => 'late', 'is_present' => true, 'is_excused' => false, 'requires_note' => false, 'color' => '#F97316'],
            ['code' => 'DISPENSASI', 'name' => 'Dispensasi', 'category' => 'excused', 'is_present' => false, 'is_excused' => true, 'requires_note' => true, 'color' => '#8B5CF6'],
            ['code' => 'DINAS', 'name' => 'Dinas / Tugas Luar', 'category' => 'excused', 'is_present' => false, 'is_excused' => true, 'requires_note' => true, 'color' => '#14B8A6'],
            ['code' => 'PULANG', 'name' => 'Pulang', 'category' => 'neutral', 'is_present' => false, 'is_excused' => false, 'requires_note' => false, 'color' => '#64748B'],
            ['code' => 'TIDAK_DIWAJIBKAN', 'name' => 'Tidak Diwajibkan', 'category' => 'neutral', 'is_present' => false, 'is_excused' => false, 'requires_note' => false, 'color' => '#94A3B8'],
        ];

        $types = [];

        foreach ($definitions as $definition) {
            $types[$definition['code']] = BoardingAttendanceType::query()->firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'category' => $definition['category'],
                    'is_present' => $definition['is_present'],
                    'is_excused' => $definition['is_excused'],
                    'requires_note' => $definition['requires_note'],
                    'requires_approval' => false,
                    'color' => $definition['color'],
                    'is_active' => true,
                ],
            );
        }

        return $types;
    }

    /**
     * Master jenis pelanggaran asrama beserta poinnya (§35, §36).
     */
    protected function violationTypes(): void
    {
        $definitions = [
            ['code' => 'TRLMBT', 'name' => 'Terlambat', 'category' => 'discipline', 'point' => 5, 'severity' => 'minor'],
            ['code' => 'TIDAK-IKUT-KEGIATAN', 'name' => 'Tidak ikut kegiatan', 'category' => 'discipline', 'point' => 10, 'severity' => 'moderate'],
            ['code' => 'KELUAR-TANPA-IZIN', 'name' => 'Keluar tanpa izin', 'category' => 'discipline', 'point' => 20, 'severity' => 'severe'],
            ['code' => 'KOTOR', 'name' => 'Tidak menjaga kebersihan', 'category' => 'cleanliness', 'point' => 5, 'severity' => 'minor'],
            ['code' => 'SERAGAM', 'name' => 'Pelanggaran seragam', 'category' => 'discipline', 'point' => 5, 'severity' => 'minor'],
            ['code' => 'TATA-TERTIB', 'name' => 'Pelanggaran tata tertib', 'category' => 'discipline', 'point' => 10, 'severity' => 'moderate'],
        ];

        foreach ($definitions as $definition) {
            BoardingViolationType::query()->firstOrCreate(
                ['code' => $definition['code']],
                $definition + ['is_active' => true],
            );
        }
    }

    /**
     * Master kegiatan harian (§16). `late_after_minutes` hanya untuk kegiatan
     * yang menghitung keterlambatan: shalat 10 menit, halaqah 15 menit (§25).
     *
     * @param  array<string, BoardingAttendanceType>  $types
     * @return array<string, BoardingActivity>
     */
    protected function activities(array $types): array
    {
        $present = $types['HADIR'] ?? null;

        $definitions = [
            ['code' => 'SUBUH', 'name' => 'Shalat Subuh Berjamaah', 'category' => 'ibadah', 'late' => 10],
            ['code' => 'DZUHUR', 'name' => 'Shalat Dzuhur Berjamaah', 'category' => 'ibadah', 'late' => 10],
            ['code' => 'ASHAR', 'name' => 'Shalat Ashar Berjamaah', 'category' => 'ibadah', 'late' => 10],
            ['code' => 'MAGHRIB', 'name' => 'Shalat Magrib Berjamaah', 'category' => 'ibadah', 'late' => 10],
            ['code' => 'ISYA', 'name' => 'Shalat Isya Berjamaah', 'category' => 'ibadah', 'late' => 10],
            ['code' => 'TAHAJJUD', 'name' => 'Shalat Tahajud', 'category' => 'ibadah', 'late' => 10, 'required' => false],
            ['code' => 'DHUHA', 'name' => 'Shalat Dhuha', 'category' => 'ibadah', 'late' => 10, 'required' => false],
            ['code' => 'HALAQAH', 'name' => 'Halaqah Tahfidz', 'category' => 'tahfidz', 'late' => 15],
            ['code' => 'TAHFIDZ', 'name' => 'Setoran Hafalan', 'category' => 'tahfidz', 'late' => null],
            ['code' => 'MAKAN-PAGI', 'name' => 'Makan Pagi', 'category' => 'meal', 'late' => null],
            ['code' => 'MAKAN-SIANG', 'name' => 'Makan Siang', 'category' => 'meal', 'late' => null],
            ['code' => 'MAKAN-SORE', 'name' => 'Makan Sore', 'category' => 'meal', 'late' => null],
            ['code' => 'BELAJAR-MALAM', 'name' => 'Belajar Malam', 'category' => 'academic', 'late' => null],
            ['code' => 'APEL', 'name' => 'Apel Sore', 'category' => 'discipline', 'late' => null],
            ['code' => 'OLAHRAGA', 'name' => 'Olahraga', 'category' => 'sports', 'late' => null, 'required' => false],
            ['code' => 'KEBERSIHAN', 'name' => 'Kebersihan Kamar', 'category' => 'cleanliness', 'late' => null],
        ];

        $activities = [];

        foreach ($definitions as $definition) {
            $activities[$definition['code']] = BoardingActivity::query()->firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'category' => $definition['category'],
                    'default_attendance_type_id' => $present?->id,
                    'late_after_minutes' => $definition['late'],
                    'is_required' => $definition['required'] ?? true,
                    'is_active' => true,
                    'description' => 'Kegiatan bawaan asrama hasil BoardingConfigSeeder.',
                ],
            );
        }

        return $activities;
    }

    /**
     * Tipe absensi yang sah per kegiatan (§18).
     *
     * Shalat: hadir/izin/sakit/alpha. Halaqah menambahkan TERLAMBAT karena ada
     * ambang 15 menit. Makan hanya tiga pilihan (tidak ada "sakit" di ruang
     * makan). Kegiatan lain memakai set dasar.
     *
     * @param  array<string, BoardingActivity>  $activities
     * @param  array<string, BoardingAttendanceType>  $types
     */
    protected function activityAttendanceTypes(array $activities, array $types): void
    {
        $mealCodes = ['MAKAN-PAGI', 'MAKAN-SIANG', 'MAKAN-SORE'];

        $sets = array_fill_keys(array_keys($activities), self::BASE_ATTENDANCE_CODES);

        // Halaqah menghitung keterlambatan (ambang 15 menit), jadi TERLAMBAT
        // ikut jadi pilihan.
        $sets['HALAQAH'] = [...self::BASE_ATTENDANCE_CODES, 'TERLAMBAT'];

        foreach ($mealCodes as $code) {
            $sets[$code] = ['HADIR', 'TIDAK-HADIR', 'IZIN'];
        }

        foreach ($sets as $activityCode => $typeCodes) {
            $activity = $activities[$activityCode] ?? null;

            if ($activity === null) {
                continue;
            }

            foreach (array_values($typeCodes) as $order => $typeCode) {
                $type = $types[$typeCode] ?? null;

                if ($type === null) {
                    continue;
                }

                BoardingActivityAttendanceType::query()->firstOrCreate(
                    [
                        'activity_id' => $activity->id,
                        'attendance_type_id' => $type->id,
                    ],
                    ['sort_order' => $order + 1],
                );
            }
        }
    }

    /**
     * Struktur fisik demo (§5): dua asrama, satu gedung, dua lantai, dua kamar
     * per lantai, delapan tempat tidur per kamar.
     *
     * Prefiks kode kamar mengikuti gender asrama — A untuk putra, B untuk putri
     * — supaya kode kamar saja sudah cukup memberitahu petugas arah mana.
     */
    protected function dormitories(): void
    {
        $blueprints = [
            [
                'code' => 'ASM-01',
                'name' => 'Asrama Al-Falah',
                'gender' => BoardingDormitory::GENDER_MALE,
                'location' => 'Kawasan A',
                'prefix' => 'A',
            ],
            [
                'code' => 'ASM-02',
                'name' => 'Asrama Assalam',
                'gender' => BoardingDormitory::GENDER_FEMALE,
                'location' => 'Kawasan B',
                'prefix' => 'B',
            ],
        ];

        foreach ($blueprints as $blueprint) {
            $dormitory = BoardingDormitory::query()->firstOrCreate(
                ['code' => $blueprint['code']],
                [
                    'name' => $blueprint['name'],
                    'gender' => $blueprint['gender'],
                    'capacity' => 32,
                    'location' => $blueprint['location'],
                    'description' => 'Asrama contoh dari BoardingConfigSeeder.',
                    'status' => BoardingDormitory::STATUS_ACTIVE,
                    'is_active' => true,
                ],
            );

            $building = BoardingBuilding::query()->firstOrCreate(
                ['dormitory_id' => $dormitory->id, 'code' => 'GED-A'],
                [
                    'name' => 'Gedung '.$blueprint['prefix'],
                    'floor_count' => 2,
                    'description' => null,
                    'status' => BoardingBuilding::STATUS_ACTIVE,
                ],
            );

            $rooms = [];

            for ($floorNumber = 1; $floorNumber <= 2; $floorNumber++) {
                $floor = BoardingFloor::query()->firstOrCreate(
                    ['building_id' => $building->id, 'floor_number' => $floorNumber],
                    ['name' => 'Lantai '.$floorNumber],
                );

                for ($index = 1; $index <= 2; $index++) {
                    $code = $blueprint['prefix'].$floorNumber.'0'.$index;

                    $rooms[] = BoardingRoom::query()->firstOrCreate(
                        ['dormitory_id' => $dormitory->id, 'code' => $code],
                        [
                            'floor_id' => $floor->id,
                            'name' => 'Kamar '.$code,
                            'capacity' => 8,
                            'gender' => $blueprint['gender'],
                            'room_type' => BoardingRoom::TYPE_STANDARD,
                            'status' => BoardingRoom::STATUS_ACTIVE,
                            'description' => null,
                        ],
                    );
                }
            }

            foreach ($rooms as $room) {
                for ($bed = 1; $bed <= 8; $bed++) {
                    BoardingBed::query()->firstOrCreate(
                        ['room_id' => $room->id, 'bed_number' => str_pad((string) $bed, 2, '0', STR_PAD_LEFT)],
                        [
                            'label' => 'Tempat Tidur '.str_pad((string) $bed, 2, '0', STR_PAD_LEFT),
                            'status' => BoardingBed::STATUS_AVAILABLE,
                        ],
                    );
                }
            }

            // Kapasitas asrama = total tempat tidur yang benar-benar ada.
            $capacity = BoardingBed::query()
                ->whereIn('room_id', BoardingRoom::query()->where('dormitory_id', $dormitory->id)->select('id'))
                ->count();

            if ((int) $dormitory->capacity !== $capacity) {
                $dormitory->update(['capacity' => $capacity]);
            }
        }
    }

    /**
     * Satu penugasan kepala asrama (§8) — hanya bila sudah ada karyawan, karena
     * seeder ini boleh dipakai pada database kosong.
     */
    protected function musyrifAssignment(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $dormitory = BoardingDormitory::query()->where('code', 'ASM-01')->first();

        if ($employee === null || $dormitory === null) {
            return;
        }

        BoardingMusyrif::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'dormitory_id' => $dormitory->id,
                'position' => BoardingMusyrif::POSITION_KEPALA_ASRAMA,
            ],
            [
                'start_date' => Carbon::today()->subYear()->toDateString(),
                'status' => BoardingMusyrif::STATUS_ACTIVE,
                'notes' => 'Penugasan contoh dari BoardingConfigSeeder.',
            ],
        );
    }
}
