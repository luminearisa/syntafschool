<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Models\LmsActivity;

/**
 * Kontrak "konfigurasi per tipe aktivitas" (§1–§2).
 *
 * `ActivityService` hanya tahu kontrak ini; Assignment, Quiz, Forum, Lesson,
 * Choice, dan Survey masing-masing menjadi konfigurator. Menambah tipe baru
 * (WORKSHOP) cukup menambah satu implementasi dan satu entri pada
 * `ActivityService::typeService()` — tanpa menyentuh mesin aktivitas.
 */
interface ActivityConfiguratorInterface
{
    /** Buat konfigurasi default untuk aktivitas baru (boleh dari payload wizard). */
    public function createForActivity(LmsActivity $activity, array $data): void;

    /** Simpan perubahan konfigurasi dari wizard/tab pengaturan. */
    public function updateForActivity(LmsActivity $activity, array $data): void;

    /** Salin konfigurasi saat aktivitas diduplikasi (§44) — tanpa riwayat. */
    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void;
}
