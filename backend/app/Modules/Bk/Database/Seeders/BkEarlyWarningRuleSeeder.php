<?php

namespace App\Modules\Bk\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Aturan peringatan dini bawaan.
 *
 * Ambangnya disimpan sebagai **data**, bukan konstanta di kode — inilah yang
 * membuat sekolah dapat mengubahnya tanpa deploy. Nilai bawaan di bawah adalah
 * titik awal yang wajar, bukan kebijakan yang dipaksakan: setiap baris dapat
 * dinonaktifkan atau diganti versinya melalui layar pengaturan.
 *
 * Semua tindakan hanya menambah pekerjaan untuk ditinjau manusia.
 */
class BkEarlyWarningRuleSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return [
            [
                'code' => 'ATTENDANCE_RATE_LOW',
                'name' => 'Kehadiran di bawah 80% dalam 30 hari',
                'metric' => 'ATTENDANCE_RATE',
                'operator' => 'LT',
                'threshold' => 80,
                'threshold_unit' => 'PERCENT',
                'window_days' => 30,
                'action' => 'CREATE_SUPPORT_INDICATOR',
                'indicator_code' => 'REPEATED_ABSENCE',
                'severity' => 'MODERATE',
                'cooldown_days' => 14,
                'description' => 'Kehadiran yang menurun sering merupakan gejala pertama sebelum masalah lain terlihat.',
            ],
            [
                'code' => 'ABSENCE_COUNT_HIGH',
                'name' => 'Absen 3 kali atau lebih dalam 30 hari',
                'metric' => 'ABSENCE_COUNT',
                'operator' => 'GTE',
                'threshold' => 3,
                'threshold_unit' => 'COUNT',
                'window_days' => 30,
                'action' => 'CREATE_BK_REVIEW',
                'indicator_code' => 'REPEATED_ABSENCE',
                'severity' => 'HIGH',
                'cooldown_days' => 7,
            ],
            [
                'code' => 'DISCIPLINE_REPEAT',
                'name' => 'Pelanggaran berulang (3 kali dalam 60 hari)',
                'metric' => 'DISCIPLINE_COUNT',
                'operator' => 'GTE',
                'threshold' => 3,
                'threshold_unit' => 'COUNT',
                'window_days' => 60,
                'action' => 'CREATE_BK_REVIEW',
                'indicator_code' => 'REPEATED_DISCIPLINE_INCIDENT',
                'severity' => 'HIGH',
                'cooldown_days' => 14,
            ],
            [
                'code' => 'ACADEMIC_AVERAGE_LOW',
                'name' => 'Rata-rata nilai di bawah ambang sekolah',
                'metric' => 'ACADEMIC_AVERAGE',
                'operator' => 'LT',
                'threshold' => 70,
                'threshold_unit' => 'POINT',
                'window_days' => 90,
                'action' => 'CREATE_ACADEMIC_SUPPORT_INDICATOR',
                'indicator_code' => 'ACADEMIC_DECLINE',
                'severity' => 'MODERATE',
                'cooldown_days' => 30,
            ],
            [
                'code' => 'MULTIPLE_REFERRALS',
                'name' => 'Dua rujukan atau lebih dalam 30 hari',
                'metric' => 'REFERRAL_COUNT',
                'operator' => 'GTE',
                'threshold' => 2,
                'threshold_unit' => 'COUNT',
                'window_days' => 30,
                'action' => 'CREATE_SUPPORT_INDICATOR',
                'indicator_code' => 'MULTIPLE_REFERRALS',
                'severity' => 'MODERATE',
                'cooldown_days' => 14,
                'description' => 'Beberapa pihak melaporkan hal serupa: perlu dikoordinasikan, bukan ditangani terpisah-pisah.',
            ],
            [
                'code' => 'FOLLOW_UP_FAILURES',
                'name' => 'Dua tindak lanjut gagal dalam 60 hari',
                'metric' => 'FOLLOW_UP_FAILURE_COUNT',
                'operator' => 'GTE',
                'threshold' => 2,
                'threshold_unit' => 'COUNT',
                'window_days' => 60,
                'action' => 'CREATE_SUPPORT_INDICATOR',
                'indicator_code' => 'REPEATED_FOLLOW_UP_FAILURE',
                'severity' => 'MODERATE',
                'cooldown_days' => 21,
                'description' => 'Bila dukungan yang sama gagal berulang, yang perlu ditinjau adalah bentuk dukungannya.',
            ],
            [
                'code' => 'CLINIC_REPEAT_VISITS',
                'name' => 'Kunjungan klinik berulang',
                'metric' => 'CLINIC_VISIT_COUNT',
                'operator' => 'GTE',
                'threshold' => 4,
                'threshold_unit' => 'COUNT',
                'window_days' => 90,
                'action' => 'NOTIFY_COORDINATOR',
                'indicator_code' => 'CLINIC_REPEAT_VISIT',
                'severity' => 'LOW',
                'cooldown_days' => 30,
                'description' => 'Menandai untuk dikoordinasikan dengan petugas klinik; isi rekam medis tetap milik Modul Clinic/UKS.',
            ],
            [
                'code' => 'ACADEMIC_DROP',
                'name' => 'Penurunan capaian akademik antar periode',
                'metric' => 'ACADEMIC_CHANGE',
                'operator' => 'LT',
                'threshold' => -10,
                'threshold_unit' => 'POINT',
                'window_days' => 90,
                'action' => 'CREATE_ACADEMIC_SUPPORT_INDICATOR',
                'indicator_code' => 'ACADEMIC_DECLINE',
                'severity' => 'MODERATE',
                'cooldown_days' => 30,
                'requires_review' => true,
            ],
        ];
    }

    public function run(): void
    {
        $now = now();

        foreach ($this->rules() as $rule) {
            $exists = DB::table('bk_early_warning_rules')
                ->where('code', $rule['code'])
                ->where('version', 1)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('bk_early_warning_rules')->insert([
                'code' => $rule['code'],
                'version' => 1,
                'name' => $rule['name'],
                'description' => $rule['description'] ?? null,
                'is_active' => true,
                // Aturan bawaan ditandai `is_system` supaya sekolah tahu mana yang
                // disediakan modul dan mana yang dibuatnya sendiri.
                'is_system' => true,
                'metric' => $rule['metric'],
                'operator' => $rule['operator'],
                'threshold' => $rule['threshold'],
                'threshold_unit' => $rule['threshold_unit'],
                'window_days' => $rule['window_days'],
                'action' => $rule['action'],
                'action_params' => null,
                'indicator_code' => $rule['indicator_code'],
                'severity' => $rule['severity'],
                'cooldown_days' => $rule['cooldown_days'],
                'scope' => null,
                'requires_review' => $rule['requires_review'] ?? true,
                'effective_from' => null,
                'effective_to' => null,
                'notes' => 'Aturan bawaan modul; dapat dinonaktifkan atau diganti versinya.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
