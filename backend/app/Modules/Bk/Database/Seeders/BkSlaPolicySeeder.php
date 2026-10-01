<?php

namespace App\Modules\Bk\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Kebijakan SLA bawaan.
 *
 * Angka waktunya adalah **data** pada tabel `bk_sla_policies`, dan setiap sekolah
 * dapat menggantinya per kategori/prioritas/severity/tipe kasus. Yang ada di sini
 * hanya titik awal: respon rujukan 24 jam, screening 3 hari, penugasan 2 hari,
 * dan kasus mendesak 4 jam.
 */
class BkSlaPolicySeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public function policies(): array
    {
        return [
            [
                'code' => 'REFERRAL_RESPONSE',
                'name' => 'Respon rujukan',
                'subject_type' => 'REFERRAL',
                'response_hours' => 24,
                'warn_before_hours' => 6,
                'escalation_level' => 'COORDINATOR',
                'applies_to' => null,
                'notes' => 'Rujukan harus direspons dalam satu hari kerja.',
            ],
            [
                'code' => 'REFERRAL_RESPONSE_URGENT',
                'name' => 'Respon rujukan mendesak',
                'subject_type' => 'REFERRAL',
                'response_hours' => 4,
                'warn_before_hours' => 1,
                'escalation_level' => 'COORDINATOR',
                'second_escalation_level' => 'MANAGEMENT',
                'applies_to' => ['priorities' => ['URGENT']],
                'notes' => 'Rujukan mendesak tidak menunggu hari berikutnya.',
            ],
            [
                'code' => 'SCREENING_WINDOW',
                'name' => 'Jendela screening',
                'subject_type' => 'SCREENING',
                'response_hours' => 72,
                'warn_before_hours' => 12,
                'escalation_level' => 'COORDINATOR',
                'applies_to' => null,
            ],
            [
                'code' => 'ASSIGNMENT_WINDOW',
                'name' => 'Penugasan konselor',
                'subject_type' => 'ASSIGNMENT',
                'response_hours' => 48,
                'warn_before_hours' => 8,
                'escalation_level' => 'COORDINATOR',
                'applies_to' => null,
                'notes' => 'Kasus tanpa konselor lebih dari dua hari menghambat seluruh alur.',
            ],
            [
                'code' => 'FOLLOW_UP_WINDOW',
                'name' => 'Penyelesaian tindak lanjut',
                'subject_type' => 'FOLLOW_UP',
                'response_hours' => 336,
                'warn_before_hours' => 48,
                'escalation_level' => 'COORDINATOR',
                'applies_to' => null,
            ],
            [
                'code' => 'URGENT_CASE',
                'name' => 'Kasus mendesak',
                'subject_type' => 'URGENT_CASE',
                'response_hours' => 4,
                'warn_before_hours' => 1,
                'escalation_level' => 'COORDINATOR',
                'second_escalation_level' => 'MANAGEMENT',
                'applies_to' => ['priorities' => ['URGENT']],
                'notes' => 'Bila tidak tersentuh dalam empat jam, koordinator dan manajemen ikut tahu.',
            ],
            [
                'code' => 'EXTERNAL_REFERRAL_WINDOW',
                'name' => 'Tindak lanjut rujukan eksternal',
                'subject_type' => 'EXTERNAL_REFERRAL',
                'response_hours' => 168,
                'warn_before_hours' => 24,
                'escalation_level' => 'COORDINATOR',
                'applies_to' => null,
            ],
            [
                'code' => 'CONSENT_WINDOW',
                'name' => 'Keputusan persetujuan',
                'subject_type' => 'CONSENT',
                'response_hours' => 72,
                'warn_before_hours' => 12,
                'escalation_level' => 'COORDINATOR',
                'applies_to' => null,
            ],
        ];
    }

    public function run(): void
    {
        $now = now();

        foreach ($this->policies() as $policy) {
            $exists = DB::table('bk_sla_policies')
                ->where('code', $policy['code'])
                ->where('version', 1)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('bk_sla_policies')->insert([
                'code' => $policy['code'],
                'version' => 1,
                'name' => $policy['name'],
                'subject_type' => $policy['subject_type'],
                'applies_to' => isset($policy['applies_to']) ? json_encode($policy['applies_to']) : null,
                'response_hours' => $policy['response_hours'],
                'resolution_hours' => $policy['resolution_hours'] ?? null,
                'warn_before_hours' => $policy['warn_before_hours'],
                'escalation_level' => $policy['escalation_level'],
                'second_escalation_level' => $policy['second_escalation_level'] ?? null,
                'is_active' => true,
                'is_system' => true,
                'notes' => $policy['notes'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
