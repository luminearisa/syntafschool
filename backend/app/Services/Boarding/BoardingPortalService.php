<?php

namespace App\Services\Boarding;

use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Portal orang tua asrama (§44–§45) — HANYA pembaca.
 *
 * ## Kenapa tidak memakai permission boarding
 *
 * Orang tua login lewat alur PPDB yang sudah ada (`ppdb_parent_accounts` →
 * `user_id`, Sanctum guard yang sama). Mereka tidak boleh memegang permission
 * admin apa pun (§56), jadi route portal cukup `auth:sanctum`; keamanannya
 * bukan permission melainkan KEPEMILIKAN: setiap endpoint memverifikasi bahwa
 * santri yang diminta benar anak pengguna ini lewat rantai
 * `student_guardians` → `ppdb_parent_accounts` — rantai yang sama yang dipakai
 * `BoardingNotificationService` untuk mengirim kabar ke arah sebaliknya.
 *
 * Layanan domain sengaja dipanggil dengan `actor = null` setelah kepemilikan
 * terbukti: `BoardingScopeService` membatasi pengasuh berdasar area binaan, dan
 * orang tua bukan pengasuh — pembatasnya di sini adalah `assertChild()`.
 */
class BoardingPortalService
{
    public function __construct(
        protected readonly BoardingStatusService $statuses,
    ) {}

    /**
     * Id santri milik pengguna (lewat akun orang tua PPDB).
     *
     * Rantainya bukan milik asrama — dipakai juga oleh portal Keuangan — jadi
     * ia hidup di `PpdbParentAccount` dan kedua modul memanggil satu sumber.
     *
     * @return list<int>
     */
    public function childStudentIds(User $user): array
    {
        return PpdbParentAccount::childStudentIds($user);
    }

    /**
     * Daftar anak + keadaan asramanya (status §42 + lokasi penempatan).
     *
     * @return list<array<string, mixed>>
     */
    public function children(User $user): array
    {
        $ids = $this->childStudentIds($user);

        if ($ids === []) {
            return [];
        }

        $students = Student::query()
            ->whereIn('id', $ids)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'nis']);

        $summaries = $this->statuses->summariesFor($ids);

        $rows = [];

        foreach ($students as $student) {
            $summary = $summaries[(int) $student->id] ?? [];

            $rows[] = [
                'student_id' => (int) $student->id,
                'full_name' => $student->full_name,
                'nis' => $student->nis,
                'in_boarding' => (bool) ($summary['in_boarding'] ?? false),
                'status' => $summary['status'] ?? BoardingStatusService::UNKNOWN,
                'status_label' => $summary['status_label'] ?? BoardingStatusService::label(null),
                'dormitory_name' => $summary['dormitory_name'] ?? null,
                'room_code' => $summary['room_code'] ?? null,
                'bed_number' => $summary['bed_number'] ?? null,
                'musyrif_name' => $summary['musyrif_name'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Gerbang kepemilikan: bukan anak pengguna → 404 (bukan 403: portal tidak
     * boleh mengonfirmasi bahwa id santri itu ada milik orang lain).
     */
    public function assertChild(User $user, mixed $studentId): int
    {
        $id = is_numeric($studentId) ? (int) $studentId : 0;

        if ($id <= 0 || ! in_array($id, $this->childStudentIds($user), true)) {
            throw new NotFoundHttpException('Data anak tidak ditemukan pada akun Anda.');
        }

        return $id;
    }
}
