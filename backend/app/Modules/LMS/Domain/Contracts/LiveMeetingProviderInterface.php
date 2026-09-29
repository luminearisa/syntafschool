<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use App\Modules\LMS\Models\LmsLiveSession;

/**
 * Kontrak penyedia pertemuan daring (§13, §17).
 *
 * Semua provider diperlakukan setara: kelas Application hanya meminta tautan
 * gabung, dukungan kehadiran, dan dukungan rekaman. Tidak ada SDK provider yang
 * di-hardcode — Zoom/Meet/custom dapat ditambahkan sebagai implementasi baru
 * tanpa mengubah domain maupun database.
 *
 * Implementasi bawaan (`LinkOnlyMeetingProvider`) hanya menyimpan tautan yang
 * diberikan guru, sehingga LMS tetap berfungsi meski sekolah belum punya akun
 * provider apa pun.
 */
interface LiveMeetingProviderInterface
{
    /** Provider yang dilayani implementasi ini. */
    public function provider(): LiveSessionProvider;

    /** Label untuk UI — sengaja tidak diterjemahkan di service. */
    public function label(): string;

    /** Apakah provider benar-benar mengirim data kehadiran (bukan tebakan). */
    public function supportsAttendance(): bool;

    /** Apakah provider menyediakan tautan rekaman otomatis. */
    public function supportsRecording(): bool;

    /** Apakah provider punya integrasi API (butuh kredensial). */
    public function supportsApiIntegration(): bool;

    /** Tautan gabung: `$asHost = true` hanya untuk pengajar/pemilik sesi. */
    public function joinUrl(LmsLiveSession $session, bool $asHost = false): ?string;

    /**
     * Buat pertemuan di sisi provider bila didukung.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null null bila provider tidak mendukung pembuatan otomatis
     */
    public function createMeeting(LmsLiveSession $session, array $options = []): ?array;

    /**
     * Ambil data kehadiran dari provider (§15).
     *
     * @return list<array<string, mixed>> daftar kosong bila provider tidak mengirim data
     */
    public function fetchAttendance(LmsLiveSession $session): array;
}
