<?php

namespace App\Services\Boarding;

use App\Models\PpdbParentAccount;
use App\Models\User;
use App\Notifications\BoardingActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pengirim notifikasi boarding.
 *
 * Controller tidak pernah mengirim notifikasi langsung — semuanya lewat service
 * ini, sama seperti `WalletNotificationService` dan `PpdbNotificationService`,
 * supaya kanal dan bentuk payload tetap satu bentuk dan bisa di-dedupe.
 *
 * Rantai penerima orang tua dipakai ulang dari modul lain: santri →
 * `student_guardians` → `ppdb_parent_accounts` (guardian_id → user_id) → akun
 * login. Tidak dibuat tabel akun wali baru (§23, §56).
 */
class BoardingNotificationService
{
    /**
     * Ke satu pengguna. ID dipakai, bukan model, karena pemilik data bisa saja
     * tidak punya akun dan panggilan tetap tidak boleh gagal.
     *
     * @param  array<string, mixed>  $context
     */
    public function toUser(?int $userId, string $type, string $title, string $body, array $context = []): bool
    {
        if ($userId === null || $this->alreadySent($userId, $type, $context)) {
            return false;
        }

        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        if ($user === null) {
            return false;
        }

        Notification::send($user, new BoardingActivityNotification($type, $title, $body, $context));

        return true;
    }

    /**
     * Ke seluruh pemegang satu permission — antrean kerja, bukan broadcast.
     *
     * @param  array<string, mixed>  $context
     * @return int jumlah penerima
     */
    public function toStaff(string $permission, string $type, string $title, string $body, array $context = []): int
    {
        $sent = 0;

        foreach ($this->holdersOf($permission) as $user) {
            if ($this->alreadySent((int) $user->id, $type, $context)) {
                continue;
            }

            Notification::send($user, new BoardingActivityNotification($type, $title, $body, $context));
            $sent++;
        }

        return $sent;
    }

    /**
     * Ke seluruh wali santri yang punya akun portal.
     *
     * Satu santri boleh punya beberapa wali; satu akun orang tua boleh dipakai
     * untuk beberapa anak — karena itu penerimanya di-distinct, dan `student_id`
     * selalu ikut di payload agar frontend tahu anak yang dimaksud.
     *
     * @param  array<string, mixed>  $context
     * @return int jumlah penerima
     */
    public function toParentsOf(int $studentId, string $type, string $title, string $body, array $context = []): int
    {
        $context += ['student_id' => $studentId];

        $sent = 0;

        foreach ($this->parentUsersOf($studentId) as $user) {
            if ($this->alreadySent((int) $user->id, $type, $context)) {
                continue;
            }

            Notification::send($user, new BoardingActivityNotification($type, $title, $body, $context));
            $sent++;
        }

        return $sent;
    }

    /**
     * Pengguna yang memegang permission — baik lewat role maupun melekat langsung.
     *
     * @return Collection<int, User>
     */
    public function holdersOf(string $permission): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($permission): void {
                $query->whereHas('roles.permissions', fn (Builder $q) => $q->where('name', $permission))
                    ->orWhereHas('permissions', fn (Builder $q) => $q->where('name', $permission));
            })
            ->get();
    }

    /**
     * Wali santri yang punya akun login (lewat `ppdb_parent_accounts`).
     *
     * @return Collection<int, User>
     */
    public function parentUsersOf(int $studentId): Collection
    {
        $guardianIds = DB::table('student_guardians')
            ->where('student_id', $studentId)
            ->pluck('guardian_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($guardianIds === []) {
            return new Collection;
        }

        $userIds = PpdbParentAccount::query()
            ->whereIn('guardian_id', $guardianIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Sudah ada notifikasi tipe yang sama untuk objek yang sama?
     *
     * Dipakai supaya pengulangan aksi (approval dua langkah, scan RFID berkali-kali)
     * tidak menumpuk pesan di kotak masuk yang sama.
     *
     * `reference_id` di-bind apa adanya, tanpa dipaksa jadi string: nilai itu
     * masuk ke kolom json sebagai number, dan `json_extract` SQLite mengembalikan
     * number — dibandingkan dengan teks ('1') tidak akan pernah sama, sehingga
     * duplikatnya lolos. Karena array context yang sama dipakai untuk menyimpan
     * dan memeriksa, bentuk aslinya justru yang paling tepat.
     *
     * @param  array<string, mixed>  $context
     */
    public function alreadySent(int $userId, string $type, array $context = []): bool
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereRaw("json_extract(data, '$.type') = ?", [$type])
            ->when(
                isset($context['reference_type'], $context['reference_id']),
                fn ($q) => $q->whereRaw(
                    "json_extract(data, '$.reference_id') = ?",
                    [$context['reference_id']],
                )->whereRaw(
                    "json_extract(data, '$.reference_type') = ?",
                    [(string) $context['reference_type']],
                ),
            )
            ->exists();
    }

    /**
     * Kabar asrama yang belum dibaca satu pengguna (portal orang tua §45).
     *
     * Ditandai dari `data.reference_type` milik model Boarding* (dengan cadangan
     * `data.route` untuk kabar yang tidak menunjuk satu baris) — notifikasi User
     * dipakai bersama lintas modul, dan portal hanya menghitung kabar asramanya
     * sendiri.
     */
    public function unreadBoardingCountFor(int $userId): int
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->where(function ($q): void {
                $q->whereRaw("json_extract(data, '$.reference_type') LIKE ?", ['%Boarding%'])
                    ->orWhereRaw("json_extract(data, '$.route') LIKE ?", ['/boarding/%']);
            })
            ->count();
    }
}
