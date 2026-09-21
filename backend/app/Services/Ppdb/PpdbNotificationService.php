<?php

namespace App\Services\Ppdb;

use App\Models\PpdbParentAccount;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi PPDB (kanal database existing).
 *
 * Dipakai bersama oleh alur pendaftaran, pembayaran, seleksi, dan pengumuman
 * sehingga tidak ada duplikasi logika notifikasi antar fitur.
 */
class PpdbNotificationService
{
    /**
     * Kirim notifikasi ke pemilik akun orang tua.
     *
     * Yang diterima adalah MODEL akun, bukan `parent_account_id`: id akun dan
     * `users.id` adalah dua ruang nomor yang berbeda dan tertukar di sini berarti
     * kabar orang tua mendarat di akun orang lain.
     */
    public function toParent(?PpdbParentAccount $account, string $type, string $title, string $body, array $context = []): bool
    {
        return $this->toUser($account?->user_id === null ? null : (int) $account->user_id, $type, $title, $body, $context);
    }

    /**
     * Kirim notifikasi ke satu `users.id` — dipakai jalur yang memang sudah
     * memegang id pengguna (mis. daftar penerima pengumuman).
     *
     * @param  array<string, mixed>  $context
     */
    public function toUser(?int $userId, string $type, string $title, string $body, array $context = []): bool
    {
        $user = $userId !== null ? User::query()->find($userId) : null;

        if ($user === null) {
            return false;
        }

        $user->notify(new PpdbActivityNotification($type, $title, $body, $context));

        return true;
    }

    /**
     * Notifikasi untuk panitia PPDB (pemegang permission tertentu).
     *
     * @param  array<string, mixed>  $context
     */
    public function toStaff(string $permission, string $type, string $title, string $body, array $context = []): int
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn (Builder $q) => $q->where('name', $permission))
            ->limit(30)
            ->get();

        foreach ($users as $user) {
            $user->notify(new PpdbActivityNotification($type, $title, $body, $context));
        }

        return $users->count();
    }

    /**
     * Notifikasi seputar pendaftaran (ke orang tua & panitia).
     *
     * @param  array<string, mixed>  $context
     */
    public function aboutRegistration(PpdbRegistration $registration, string $type, string $title, string $body, array $context = []): void
    {
        $context += [
            'registration_number' => $registration->registration_number,
            'registration_id' => $registration->id,
        ];

        $account = $registration->parentAccount
            ?? PpdbParentAccount::query()->find($registration->parent_account_id);

        $this->toParent($account, $type, $title, $body, $context);
    }

    /**
     * Daftar notifikasi PPDB milik pengguna.
     */
    public function listFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->notifications()
            ->where('type', PpdbActivityNotification::class)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return (int) $user->unreadNotifications()
            ->where('type', PpdbActivityNotification::class)
            ->count();
    }

    public function markAsRead(User $user, string $id): bool
    {
        $notification = $user->notifications()
            ->where('type', PpdbActivityNotification::class)
            ->whereKey($id)
            ->first();

        if ($notification === null) {
            return false;
        }

        $notification->markAsRead();

        return true;
    }

    public function markAllAsRead(User $user): int
    {
        return (int) $user->unreadNotifications()
            ->where('type', PpdbActivityNotification::class)
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * Apakah notifikasi sejenis untuk referensi ini sudah pernah dikirim?
     * (dipakai pengingat terjadwal agar tidak menggandakan notifikasi)
     *
     * @param  array<string, mixed>  $context
     */
    public function alreadySent(int $userId, string $type, ?string $reference = null): bool
    {
        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('type', PpdbActivityNotification::class)
            ->where('data->type', $type);

        if ($reference !== null) {
            $query->where('data->registration_number', $reference);
        }

        return $query->exists();
    }
}