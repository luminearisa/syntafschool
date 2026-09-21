<?php

namespace App\Services\Ppdb;

use App\Models\PpdbApplicantDocument;
use App\Models\PpdbInvoice;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationHistory;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin status pendaftaran PPDB.
 *
 * Semua perubahan status pendaftaran — submit oleh orang tua, verifikasi oleh
 * panitia, hasil seleksi, daftar ulang, sampai pembatalan — lewat kelas ini agar
 * aturan TRANSITIONS, riwayat (ppdb_registration_histories), dan notifikasi tidak
 * disalin per controller.
 *
 * Status pembayaran & kelengkapan dokumen ikut disinkronkan dari data sumbernya
 * (invoice & dokumen), bukan disimpan sebagai kebenaran terpisah.
 */
class PpdbRegistrationStateMachine
{
    public function __construct(private readonly PpdbNotificationService $notifications)
    {
    }

    public function canTransition(PpdbRegistration $registration, string $to): bool
    {
        return in_array($to, $registration->allowedTransitions(), true);
    }

    /**
     * Pindahkan status + tulis riwayat + kirim notifikasi.
     *
     * @throws ValidationException bila perpindahan tidak diizinkan
     */
    public function transition(
        PpdbRegistration $registration,
        string $to,
        ?string $note = null,
        ?User $actor = null,
        bool $notify = true,
    ): PpdbRegistration {
        if (! in_array($to, PpdbRegistration::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => "Status \"$to\" tidak dikenal."]);
        }

        $from = (string) $registration->status;

        if ($from === $to) {
            throw ValidationException::withMessages(['status' => 'Pendaftaran sudah berada pada status tersebut.']);
        }

        if (! $this->canTransition($registration, $to)) {
            throw ValidationException::withMessages([
                'status' => "Perubahan status \"$from\" ke \"$to\" tidak diizinkan.",
            ]);
        }

        return DB::transaction(function () use ($registration, $from, $to, $note, $actor, $notify) {
            $registration->status = $to;
            $this->applyTimestamps($registration, $to, $actor);

            if ($to === PpdbRegistration::STATUS_NEED_REVISION) {
                $registration->revision_note = $note;
            }

            $this->syncDocumentStatus($registration);
            $this->syncPaymentStatus($registration);
            $registration->save();

            $this->record($registration, 'status.'.$to, $note, $actor, $from, $to);

            if ($notify) {
                $this->notify($registration, $to, $note);
            }

            return $registration->refresh();
        });
    }

    /**
     * Catat peristiwa pada riwayat tanpa mengubah status (mis. unggah dokumen,
     * pembayaran diverifikasi, nilai seleksi dimasukkan).
     */
    public function record(
        PpdbRegistration $registration,
        string $action,
        ?string $note = null,
        ?User $actor = null,
        ?string $from = null,
        ?string $to = null,
    ): PpdbRegistrationHistory {
        return PpdbRegistrationHistory::create([
            'registration_id' => $registration->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changed_by' => $actor?->id,
        ]);
    }

    /**
     * Hitung ulang kelengkapan dokumen dari versi aktif tiap persyaratan.
     */
    public function syncDocumentStatus(PpdbRegistration $registration): string
    {
        $documents = PpdbApplicantDocument::query()
            ->where('registration_id', $registration->id)
            ->active()
            ->get(['status']);

        $status = PpdbRegistration::DOCUMENT_NOT_UPLOADED;

        if ($documents->isNotEmpty()) {
            $statuses = $documents->pluck('status')->all();

            $status = match (true) {
                in_array(PpdbApplicantDocument::STATUS_REJECTED, $statuses, true) => PpdbRegistration::DOCUMENT_REJECTED,
                count($statuses) === count(array_filter($statuses, fn ($s) => $s === PpdbApplicantDocument::STATUS_APPROVED))
                    => PpdbRegistration::DOCUMENT_APPROVED,
                default => PpdbRegistration::DOCUMENT_UNDER_REVIEW,
            };
        }

        $registration->document_status = $status;

        return $status;
    }

    /**
     * Hitung ulang status pembayaran dari tagihan pendaftaran.
     */
    public function syncPaymentStatus(PpdbRegistration $registration): string
    {
        $statuses = PpdbInvoice::query()
            ->where('registration_id', $registration->id)
            ->pluck('status')
            ->all();

        $status = match (true) {
            $statuses === [] => PpdbRegistration::PAYMENT_UNPAID,
            count(array_filter($statuses, fn ($s) => $s === PpdbInvoice::STATUS_PAID)) === count($statuses)
                => PpdbRegistration::PAYMENT_PAID,
            in_array(PpdbInvoice::STATUS_PENDING, $statuses, true) => PpdbRegistration::PAYMENT_PENDING,
            in_array(PpdbInvoice::STATUS_UNPAID, $statuses, true) => PpdbRegistration::PAYMENT_UNPAID,
            in_array(PpdbInvoice::STATUS_FAILED, $statuses, true) => PpdbRegistration::PAYMENT_FAILED,
            in_array(PpdbInvoice::STATUS_EXPIRED, $statuses, true) => PpdbRegistration::PAYMENT_EXPIRED,
            in_array(PpdbInvoice::STATUS_REFUNDED, $statuses, true) => PpdbRegistration::PAYMENT_REFUNDED,
            in_array(PpdbInvoice::STATUS_CANCELLED, $statuses, true) => PpdbRegistration::PAYMENT_CANCELLED,
            default => PpdbRegistration::PAYMENT_UNPAID,
        };

        $registration->payment_status = $status;

        return $status;
    }

    private function applyTimestamps(PpdbRegistration $registration, string $to, ?User $actor): void
    {
        match ($to) {
            PpdbRegistration::STATUS_SUBMITTED => $registration->submitted_at = now(),
            PpdbRegistration::STATUS_VERIFIED => $registration->verified_at = now(),
            PpdbRegistration::STATUS_PASSED,
            PpdbRegistration::STATUS_FAILED,
            PpdbRegistration::STATUS_WAITLISTED => $registration->announced_at = now(),
            PpdbRegistration::STATUS_COMPLETED => $registration->completed_at = now(),
            default => null,
        };

        if ($to === PpdbRegistration::STATUS_VERIFIED && $actor !== null) {
            $registration->verified_by = $actor->id;
        }
    }

    private function notify(PpdbRegistration $registration, string $to, ?string $note): void
    {
        $number = $registration->registration_number;

        [$type, $title, $body] = match ($to) {
            PpdbRegistration::STATUS_SUBMITTED => [
                PpdbActivityNotification::TYPE_REGISTRATION_SUBMITTED,
                'Pendaftaran terkirim',
                "Pendaftaran $number sudah kami terima dan menunggu verifikasi.",
            ],
            PpdbRegistration::STATUS_NEED_REVISION => [
                PpdbActivityNotification::TYPE_REVISION_REQUESTED,
                'Data perlu direvisi',
                $note !== null && $note !== ''
                    ? "Pendaftaran $number perlu diperbaiki: $note"
                    : "Pendaftaran $number perlu diperbaiki. Silakan periksa data Anda.",
            ],
            PpdbRegistration::STATUS_VERIFIED => [
                PpdbActivityNotification::TYPE_REGISTRATION_VERIFIED,
                'Pendaftaran terverifikasi',
                "Pendaftaran $number telah diverifikasi.",
            ],
            PpdbRegistration::STATUS_PASSED => [
                PpdbActivityNotification::TYPE_SELECTION_RESULT,
                'Selamat, Anda diterima',
                "Calon dengan pendaftaran $number DITERIMA. Silakan lakukan daftar ulang.",
            ],
            PpdbRegistration::STATUS_FAILED => [
                PpdbActivityNotification::TYPE_SELECTION_RESULT,
                'Hasil seleksi',
                "Maaf, pendaftaran $number belum berhasil pada seleksi ini.",
            ],
            PpdbRegistration::STATUS_WAITLISTED => [
                PpdbActivityNotification::TYPE_SELECTION_RESULT,
                'Hasil seleksi: daftar tunggu',
                "Pendaftaran $number masuk daftar tunggu.",
            ],
            PpdbRegistration::STATUS_RE_REGISTRATION => [
                PpdbActivityNotification::TYPE_RE_REGISTRATION_OPEN,
                'Daftar ulang dibuka',
                "Daftar ulang untuk $number telah dibuka.",
            ],
            PpdbRegistration::STATUS_COMPLETED => [
                PpdbActivityNotification::TYPE_RE_REGISTRATION_OPEN,
                'Daftar ulang selesai',
                "Daftar ulang $number selesai. Calon resmi menjadi siswa.",
            ],
            PpdbRegistration::STATUS_CANCELLED => [
                PpdbActivityNotification::TYPE_REGISTRATION_CANCELLED,
                'Pendaftaran dibatalkan',
                "Pendaftaran $number dibatalkan.",
            ],
            default => [null, '', ''],
        };

        if ($type !== null) {
            $this->notifications->aboutRegistration($registration, $type, $title, $body, [
                'status' => $to,
            ]);
        }
    }
}
