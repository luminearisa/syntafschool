<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Notifications\WalletActivityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pengirim notifikasi wallet.
 *
 * Controller tidak pernah mengirim notifikasi langsung — semuanya lewat service
 * ini, sama seperti `PpdbNotificationService`, supaya kanal dan bentuk payload
 * tetap satu bentuk dan bisa di-dedupe.
 */
class WalletNotificationService
{
    /**
     * Ke satu pengguna (pemilik wallet). ID dipakai, bukan model, karena pemilik
     * bisa saja tidak punya akun dan panggilan tetap tidak boleh gagal.
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

        Notification::send($user, new WalletActivityNotification($type, $title, $body, $context));

        return true;
    }

    /**
     * Ke seluruh pemegang satu permission — antrean kerja, bukan broadcast.
     *
     * @return int jumlah penerima
     */
    public function toStaff(string $permission, string $type, string $title, string $body, array $context = []): int
    {
        $sent = 0;

        foreach ($this->holdersOf($permission) as $user) {
            if ($this->alreadySent((int) $user->id, $type, $context)) {
                continue;
            }

            Notification::send($user, new WalletActivityNotification($type, $title, $body, $context));
            $sent++;
        }

        return $sent;
    }

    /**
     * Pengguna yang memegang permission — baik lewat role maupun melekat langsung.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function holdersOf(string $permission): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($permission): void {
                $query->whereHas('roles.permissions', fn ($q) => $q->where('name', $permission))
                    ->orWhereHas('permissions', fn ($q) => $q->where('name', $permission));
            })
            ->get();
    }

    /**
     * Sudah ada notifikasi tipe yang sama untuk objek yang sama?
     *
     * Dipakai supaya webhook gateway yang datang berulang tidak menumpuk pesan
     * (saldo sudah dijaga `WalletLedgerService`; ini menjaga kotak masuk).
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
                    [(string) $context['reference_id']],
                )->whereRaw(
                    "json_extract(data, '$.reference_type') = ?",
                    [(string) $context['reference_type']],
                ),
            )
            ->exists();
    }
}
