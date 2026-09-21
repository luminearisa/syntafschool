<?php

namespace App\Services\Library;

use App\Models\LibraryMember;
use App\Models\LibraryLoan;
use App\Models\LibraryReservation;
use App\Models\User;
use App\Notifications\LibraryActivityNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi modul Library.
 *
 * Memakai kanal `database` bawaan Laravel (User sudah `Notifiable`) — tidak
 * ada notification engine baru, dan tidak menduplikasi tabel notifikasi Tahfiz.
 *
 * Semua pengiriman bersifat **idempoten**: satu jenis notifikasi untuk satu
 * referensi (pinjaman/reservasi) hanya dibuat sekali, sehingga scheduler boleh
 * berjalan berulang tanpa membanjiri pengguna.
 */
class LibraryNotificationService
{
    /**
     * Kirim notifikasi ke pemilik akun seorang anggota.
     *
     * Anggota tanpa akun (mis. keanggotaan fisik) dilewati — pengingat mereka
     * ditangani petugas.
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyMember(LibraryMember $member, string $type, string $title, string $body, array $context = []): bool
    {
        $user = $member->user_id !== null
            ? User::query()->find($member->user_id)
            : null;

        if ($user === null || ! $this->shouldSend($user->id, $type, $context)) {
            return false;
        }

        $user->notify(new LibraryActivityNotification($type, $title, $body, $context));

        return true;
    }

    /**
     * Notifikasi untuk staf perpustakaan (mis. denda dibuat).
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyStaff(string $permission, string $type, string $title, string $body, array $context = []): int
    {
        $sent = 0;

        // Staf penerima ditentukan dari pemegang permission terkait.
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn (Builder $q) => $q->where('name', $permission))
            ->limit(20)
            ->get();

        foreach ($users as $user) {
            if (! $this->shouldSend($user->id, $type, $context)) {
                continue;
            }

            $user->notify(new LibraryActivityNotification($type, $title, $body, $context));
            $sent++;
        }

        return $sent;
    }

    /**
     * Pengingat jatuh tempo (H- beberapa hari).
     */
    public function remindDueSoon(?int $libraryId = null, ?int $daysBefore = null): int
    {
        $daysBefore ??= 2;
        $target = Carbon::now()->addDays($daysBefore)->toDateString();

        $loans = LibraryLoan::query()
            ->open()
            ->whereDate('due_at', $target)
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->with(['member', 'bibliography:id,title'])
            ->get();

        $sent = 0;

        foreach ($loans as $loan) {
            if ($loan->member === null) {
                continue;
            }

            $sent += (int) $this->notifyMember(
                $loan->member,
                LibraryActivityNotification::TYPE_DUE_SOON,
                'Koleksi akan jatuh tempo',
                sprintf('"%s" harus dikembalikan pada %s.', $loan->bibliography?->title ?? 'Koleksi', $loan->due_at?->toDateString()),
                ['loan_code' => $loan->loan_code, 'due_at' => $loan->due_at?->toDateString()]
            );
        }

        return $sent;
    }

    /**
     * Pemberitahuan keterlambatan.
     */
    public function remindOverdue(?int $libraryId = null): int
    {
        $loans = LibraryLoan::query()
            ->open()
            ->whereDate('due_at', '<', Carbon::now()->toDateString())
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->with(['member', 'bibliography:id,title'])
            ->get();

        $sent = 0;

        foreach ($loans as $loan) {
            if ($loan->member === null) {
                continue;
            }

            $sent += (int) $this->notifyMember(
                $loan->member,
                LibraryActivityNotification::TYPE_OVERDUE,
                'Koleksi terlambat dikembalikan',
                sprintf('"%s" terlambat %d hari.', $loan->bibliography?->title ?? 'Koleksi', $loan->overdueDays()),
                ['loan_code' => $loan->loan_code, 'overdue_days' => $loan->overdueDays()]
            );
        }

        return $sent;
    }

    /**
     * Pemberitahuan keanggotaan yang akan berakhir (H-14).
     */
    public function remindMembershipExpiring(?int $libraryId = null, int $daysBefore = 14): int
    {
        $members = LibraryMember::query()
            ->whereIn('status', LibraryMember::ACTIVE_STATUSES)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '>=', Carbon::now()->toDateString())
            ->whereDate('valid_until', '<=', Carbon::now()->addDays($daysBefore)->toDateString())
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->get();

        $sent = 0;

        foreach ($members as $member) {
            $sent += (int) $this->notifyMember(
                $member,
                LibraryActivityNotification::TYPE_MEMBERSHIP_EXPIRING,
                'Keanggotaan akan berakhir',
                sprintf('Keanggotaan Anda berakhir pada %s.', $member->valid_until?->toDateString()),
                ['member_number' => $member->member_number, 'valid_until' => $member->valid_until?->toDateString()]
            );
        }

        return $sent;
    }

    /**
     * Pemberitahuan reservasi yang sudah dapat diambil.
     */
    public function notifyReservationsAvailable(?int $libraryId = null): int
    {
        $reservations = LibraryReservation::query()
            ->where('status', LibraryReservation::STATUS_AVAILABLE)
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->with(['member', 'bibliography:id,title'])
            ->get();

        $sent = 0;

        foreach ($reservations as $reservation) {
            if ($reservation->member === null) {
                continue;
            }

            $sent += (int) $this->notifyMember(
                $reservation->member,
                LibraryActivityNotification::TYPE_RESERVATION_AVAILABLE,
                'Reservasi siap diambil',
                sprintf('"%s" telah tersedia. Ambil sebelum %s.', $reservation->bibliography?->title ?? 'Koleksi', $reservation->expires_at?->toDateString()),
                ['reservation_code' => $reservation->reservation_code, 'expires_at' => $reservation->expires_at?->toDateString()]
            );
        }

        return $sent;
    }

    /**
     * Pemberitahuan denda baru (ke anggota; salinan ke staf penagih).
     */
    public function notifyFineCreated(\App\Models\LibraryFine $fine): int
    {
        $member = $fine->member;

        if ($member === null) {
            return 0;
        }

        return (int) $this->notifyMember(
            $member,
            LibraryActivityNotification::TYPE_FINE_CREATED,
            'Denda perpustakaan',
            sprintf('Denda %s sebesar %s dicatat.', $fine->type, number_format((float) $fine->amount, 2)),
            ['fine_id' => $fine->id, 'amount' => (float) $fine->amount]
        );
    }

    /**
     * Daftar notifikasi Library milik pengguna.
     */
    public function listFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->notifications()
            ->where('type', LibraryActivityNotification::class)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return (int) $user->unreadNotifications()
            ->where('type', LibraryActivityNotification::class)
            ->count();
    }

    public function markAsRead(User $user, string $id): bool
    {
        $notification = $user->notifications()
            ->where('type', LibraryActivityNotification::class)
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
            ->where('type', LibraryActivityNotification::class)
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * Apakah notifikasi jenis ini untuk referensi ini sudah pernah dikirim?
     *
     * @param  array<string, mixed>  $context
     */
    protected function shouldSend(int $userId, string $type, array $context): bool
    {
        $reference = $context['loan_code']
            ?? $context['reservation_code']
            ?? $context['member_number']
            ?? $context['fine_id']
            ?? null;

        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('type', LibraryActivityNotification::class)
            ->where('data->type', $type);

        if ($reference !== null) {
            // Referensi disimpan pada context; bandingkan lewat JSON path.
            $key = array_key_exists('loan_code', $context) ? 'loan_code'
                : (array_key_exists('reservation_code', $context) ? 'reservation_code'
                : (array_key_exists('member_number', $context) ? 'member_number' : 'fine_id'));

            return ! (clone $query)->where("data->{$key}", $reference)->exists();
        }

        return ! $query->exists();
    }
}