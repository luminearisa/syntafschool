<?php

namespace App\Services\Ppdb;

use App\Models\PpdbApplicant;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gerbang kepemilikan data portal orang tua.
 *
 * Route portal memakai implicit binding, sehingga ID apa pun akan ter-resolve.
 * Tanpa pemeriksaan di sini, cukup tebak nomor untuk membaca data orang lain.
 * Semua endpoint portal WAJIB lewat kelas ini — satu tempat, satu aturan.
 */
class PpdbPortalAccess
{
    public function account(?User $user): ?PpdbParentAccount
    {
        if ($user === null) {
            return null;
        }

        return PpdbParentAccount::query()->where('user_id', $user->id)->first();
    }

    /**
     * Akun yang boleh bertransaksi. Ditolak bila belum terdaftar, belum
     * terverifikasi, atau diblokir panitia.
     */
    public function requireAccount(User $user): PpdbParentAccount
    {
        $account = $this->account($user);

        if ($account === null) {
            throw $this->deny('Akun PPDB Anda tidak ditemukan. Silakan daftar terlebih dahulu.');
        }

        if (! $account->canTransact()) {
            throw $this->deny('Akun PPDB Anda belum aktif. Hubungi panitia PPDB untuk bantuan.');
        }

        $account->forceFill(['last_activity_at' => now()])->save();

        return $account;
    }

    public function owns(PpdbParentAccount $account, PpdbRegistration $registration): bool
    {
        return (int) $registration->parent_account_id === (int) $account->id;
    }

    public function registration(User $user, PpdbRegistration $registration): PpdbRegistration
    {
        $account = $this->requireAccount($user);

        if (! $this->owns($account, $registration)) {
            throw $this->deny('Pendaftaran tersebut bukan milik Anda.');
        }

        return $registration;
    }

    public function applicant(User $user, PpdbApplicant $applicant): PpdbApplicant
    {
        $account = $this->requireAccount($user);

        if ((int) $applicant->parent_account_id !== (int) $account->id) {
            throw $this->deny('Data calon siswa tersebut bukan milik Anda.');
        }

        return $applicant;
    }

    /** Dokumen milik pendaftaran, bukan milik akun — jadi ikut diperiksa di sini. */
    public function document(User $user, PpdbApplicantDocument $document): PpdbApplicantDocument
    {
        $registration = $document->registration;

        if ($registration === null) {
            throw $this->deny('Dokumen ini tidak lagi terhubung ke pendaftaran aktif.');
        }

        $this->registration($user, $registration);

        return $document;
    }

    public function invoice(User $user, PpdbInvoice $invoice): PpdbInvoice
    {
        $registration = $invoice->registration;

        if ($registration === null) {
            throw $this->deny('Tagihan ini tidak lagi terhubung ke pendaftaran aktif.');
        }

        $this->registration($user, $registration);

        return $invoice;
    }

    /** Notifikasi hanya boleh dibaca oleh pemiliknya. */
    public function notification(User $user, Model $notification): Model
    {
        if ((int) $notification->notifiable_id !== (int) $user->id
            || $notification->notifiable_type !== $user::class) {
            throw $this->deny('Notifikasi ini bukan milik Anda.');
        }

        return $notification;
    }

    private function deny(string $message): HttpException
    {
        return new HttpException(403, $message);
    }
}
