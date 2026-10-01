<?php

namespace App\Modules\Bk\Database\Seeders;

use App\Modules\Bk\Domain\Enums\RetentionEntityType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Kebijakan retensi bawaan.
 *
 * Nilai bawaan diambil dari `RetentionEntityType::fallbackDays()` — satu tempat
 * yang menjelaskan alasan setiap masa simpan, sehingga kebijakan di basis data
 * dan alasan di kode tidak dapat berbeda tanpa terlihat.
 *
 * Semua kebijakan menuntut persetujuan sebelum pemusnahan, kecuali bila sekolah
 * secara sadar mematikannya.
 */
class BkRetentionPolicySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (RetentionEntityType::cases() as $entity) {
            $exists = DB::table('bk_retention_policies')
                ->where('entity_type', $entity->value)
                ->where('version', 1)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('bk_retention_policies')->insert([
                'entity_type' => $entity->value,
                'version' => 1,
                'retention_days' => $entity->fallbackDays(),
                // Rekaman sensitif diarsipkan lebih awal agar tidak lagi muncul pada
                // daftar kerja sehari-hari, tanpa menghilangkannya dari yang berhak.
                'archive_after_days' => $entity->isHighlySensitive() ? max(180, (int) round($entity->fallbackDays() / 2)) : null,
                'requires_approval_for_disposal' => true,
                'is_active' => true,
                'legal_basis' => $entity->isHighlySensitive()
                    ? 'Kepentingan layanan dukungan siswa; akses dibatasi dan pemusnahan menunggu persetujuan.'
                    : 'Kebutuhan operasional dan pelaporan sekolah.',
                'notes' => 'Kebijakan bawaan modul; dapat diganti dengan versi baru tanpa kehilangan riwayat kebijakan.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
