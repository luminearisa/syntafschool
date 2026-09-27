<?php

namespace App\Services\Finance;

use App\Models\FinanceInvoice;
use App\Models\FinanceOperation;
use App\Models\FinancePayment;
use App\Models\FinancePaymentPlanInstallment;
use App\Models\FinanceRefund;
use App\Models\FinanceReRegistration;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use App\Notifications\FinanceActivityNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi modul Keuangan (§30–§31 Phase 2, §8 Phase 5).
 *
 * Memakai sistem notifikasi bawaan Laravel — tidak membuat engine baru. Penerima
 * sebuah tagihan/pembayaran adalah santri itu sendiri (akun `userable` Student)
 * plus wali akun PPDB-nya, sehingga seorang wali hanya menerima anak milik
 * sendiri dan tidak bocor ke santri lain (§24, §30).
 *
 * Kelas ini memilih PERISTIWA dan angkanya; kalimatnya tinggal di
 * `FinanceNotificationTemplate` supaya sekolah bisa mengubah bunyi surat tanpa
 * mengubah kode (§8: "Gunakan template"). Kanal juga diputuskan di sini:
 * kotak masuk in-app selalu menyala, email ikut menyala bila sekolah membuka
 * `notification_email_enabled` dan tipenya termasuk surat keluarga.
 *
 * Semua pengiriman idempoten: satu jenis notifikasi untuk satu dokumen hanya
 * dibuat sekali per penerima, jadi generasi massal / scheduler berulang tidak
 * membanjiri pengguna.
 */
class FinanceNotificationService
{
    /** Nama santri per id, dibaca sekali per jalan (satu tangga pengingat = banyak dokumen). */
    private array $studentNames = [];

    public function __construct(
        private readonly FinanceOperationService $operations,
        private readonly FinanceNotificationTemplate $templates,
        private readonly FinanceSettingsService $settings,
    ) {}

    /** Kirim satu notifikasi ke satu akun. False bila ditahan deduplikasi. */
    public function toUser(?int $userId, string $type, string $title, string $body, array $context = []): bool
    {
        $user = $userId !== null ? User::query()->whereKey($userId)->where('is_active', true)->first() : null;

        if ($user === null || ! $this->shouldSend((int) $user->id, $type, $context)) {
            return false;
        }

        try {
            $user->notify(new FinanceActivityNotification($type, $title, $body, $context, $this->channelsFor($type)));
        } catch (\Throwable $e) {
            // Notifikasi adalah efek samping dari sebuah aksi keuangan, bukan
            // bagian dari angkanya. Bila kanal mati (queue rusak, driver email
            // tidak terisi) lalu kegagalan ini dibiarkan melempar, pembayaran
            // yang sudah sah ikut gagal karena suratnya. Yang tersisa hanyalah
            // jejaknya (§29); `retryable` false karena tidak ada jalur ulang dari
            // sini — aksi aslinya yang diulang, dan deduplikasi di atas menahan
            // surat ganda kalau ia memang terkirim.
            $this->operations->recordIncident(
                FinanceOperation::EVENT_NOTIFICATION_SEND,
                $e,
                ['user_id' => (int) $user->id, 'type' => $type, 'reference' => $context['reference'] ?? null],
                retryable: false,
            );

            return false;
        }

        return true;
    }

    /**
     * Kanal untuk sebuah tipe surat.
     *
     * Settings dibaca setiap kali, tidak disimpan di properti: satu proses
     * scheduler boleh saja panjang, tapi saklar kanal adalah kebijakan yang
     * bisa diubah bendahara kapan pun, dan menahannya di memori membuat
     * "sudah saya aktifkan kok tidak kirim-kirim" tidak pernah bisa dijawab.
     *
     * Publik karena layar notifikasi (§31) perlu menulis "surat ini juga dikirim
     * ke email" tanpa menebak-nebak kebijakan.
     *
     * @return list<string>
     */
    public function channelsFor(string $type): array
    {
        $channels = ['database'];

        if (FinanceActivityNotification::isMailed($type)
            && (bool) $this->settings->get('notification_email_enabled', false)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Kirim ke santri + wali akun PPDB-nya.
     *
     * @return int jumlah notifikasi terkirim
     */
    public function notifyStudent(?int $studentId, string $type, string $title, string $body, array $context = []): int
    {
        $sent = 0;

        foreach ($this->recipientsOf($studentId) as $userId) {
            $sent += (int) $this->toUser($userId, $type, $title, $body, $context);
        }

        return $sent;
    }

    /** Notifikasi ke pemegang sebuah permission (mis. petugas yang harus verifikasi). */
    public function notifyStaff(string $permission, string $type, string $title, string $body, array $context = []): int
    {
        $sent = 0;

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn (Builder $q) => $q->where('name', $permission))
            ->limit(20)
            ->get();

        foreach ($users as $user) {
            $sent += (int) $this->toUser((int) $user->id, $type, $title, $body, $context);
        }

        return $sent;
    }

    // ==================== Peristiwa Keuangan ====================

    /** Tagihan terbit → beri tahu santri + walinya. */
    public function invoiceIssued(FinanceInvoice $invoice): int
    {
        if ($invoice->student_id === null) {
            return 0;
        }

        [$title, $body] = $this->templates->render('invoice_issued', $this->invoiceData($invoice) + [
            'amount_text' => self::money((float) $invoice->total),
        ]);

        return $this->notifyStudent(
            (int) $invoice->student_id,
            FinanceActivityNotification::TYPE_INVOICE_ISSUED,
            $title,
            $body,
            [
                'reference' => $invoice->invoice_number,
                'invoice_id' => (int) $invoice->id,
                'amount' => (float) $invoice->total,
                'due_date' => $invoice->due_date?->toDateString(),
            ],
        );
    }

    /**
     * Pembayaran diterima & dilunaskan → kuitansi tersedia.
     *
     * Peristiwa "receipt issued" di §8 adalah momen yang sama dengan ini: kuitansi
     * lahir bersamaan dengan konfirmasi pembayaran, jadi nomornya disebut di sini
     * alih-alih menjadi surat terpisah. Dua pesan untuk satu pembayaran adalah
     * spam, dan penanda referensinya akan tetap sama.
     */
    public function paymentReceived(FinancePayment $payment): int
    {
        $receiptNumber = $payment->receipt?->receipt_number;

        [$title, $body] = $this->templates->render('payment_received', $this->paymentData($payment) + [
            // Kuitansi ada atau tidak ada — kalimatnya tetap satu baris, jadi
            // bagiannya disiapkan di sini sebagai nilai token, bukan sebagai
            // dua template yang harus disunting sepasang-sepasang.
            'receipt_text' => $receiptNumber !== null ? " (kuitansi {$receiptNumber})" : '',
            'receipt_number' => $receiptNumber ?? '-',
        ]);

        return $this->notifyStudent(
            $payment->student_id !== null ? (int) $payment->student_id : null,
            FinanceActivityNotification::TYPE_PAYMENT_RECEIVED,
            $title,
            $body,
            [
                'reference' => $payment->payment_number,
                'payment_id' => (int) $payment->id,
                'invoice_id' => $payment->invoice_id !== null ? (int) $payment->invoice_id : null,
                'amount' => (float) $payment->amount,
                'receipt_number' => $receiptNumber,
            ],
        );
    }

    /** Pembayaran manual/gateway menunggu verifikasi → beri tahu petugas. */
    public function paymentPendingVerification(FinancePayment $payment): int
    {
        [$title, $body] = $this->templates->render('payment_pending_verification', $this->paymentData($payment));

        return $this->notifyStaff(
            'finance.payment.verify',
            FinanceActivityNotification::TYPE_PAYMENT_PENDING_VERIFICATION,
            $title,
            $body,
            [
                'reference' => $payment->payment_number,
                'payment_id' => (int) $payment->id,
                'amount' => (float) $payment->amount,
            ],
        );
    }

    /**
     * Pembayaran tidak jadi → beri tahu santri + walinya.
     *
     * Dua sebab yang berbeda cerita: `providerReported` true berarti kanal bayar
     * melapor gagal (webhook `failed` — uang tidak pernah meninggalkan rekening
     * wali), sedangkan yang lain berarti petugas menilai pembayaran itu tidak sah
     * dan mencantumkan alasannya. Pesannya dipisah supaya wali tidak membaca
     * "ditolak" untuk transaksi yang sebenarnya tidak pernah terjadi (§8).
     */
    public function paymentRejected(FinancePayment $payment, bool $providerReported = false): int
    {
        $data = $this->paymentData($payment) + ['reason' => $payment->reject_reason];

        if ($providerReported) {
            [$title, $body] = $this->templates->render('payment_failed', $data);

            return $this->notifyStudent(
                $payment->student_id !== null ? (int) $payment->student_id : null,
                FinanceActivityNotification::TYPE_PAYMENT_FAILED,
                $title,
                $body,
                [
                    'reference' => $payment->payment_number.':failed',
                    'payment_id' => (int) $payment->id,
                    'invoice_id' => $payment->invoice_id !== null ? (int) $payment->invoice_id : null,
                    'amount' => (float) $payment->amount,
                    'reason' => $payment->reject_reason,
                ],
            );
        }

        [$title, $body] = $this->templates->render('payment_rejected', $data);

        return $this->notifyStudent(
            $payment->student_id !== null ? (int) $payment->student_id : null,
            FinanceActivityNotification::TYPE_PAYMENT_REJECTED,
            $title,
            $body,
            [
                'reference' => $payment->payment_number.':rejected',
                'payment_id' => (int) $payment->id,
                'reason' => $payment->reject_reason,
            ],
        );
    }

    /** Pembayaran dibatalkan → beri tahu santri + walinya. */
    public function paymentCancelled(FinancePayment $payment): int
    {
        [$title, $body] = $this->templates->render('payment_cancelled', $this->paymentData($payment) + [
            'reason' => $payment->reject_reason,
        ]);

        return $this->notifyStudent(
            $payment->student_id !== null ? (int) $payment->student_id : null,
            FinanceActivityNotification::TYPE_PAYMENT_CANCELLED,
            $title,
            $body,
            [
                'reference' => $payment->payment_number.':cancelled',
                'payment_id' => (int) $payment->id,
                'reason' => $payment->reject_reason,
            ],
        );
    }

    /** Pembayaran gateway/VA kedaluwarsa → beri tahu santri + walinya. */
    public function paymentExpired(FinancePayment $payment): int
    {
        [$title, $body] = $this->templates->render('payment_expired', $this->paymentData($payment));

        return $this->notifyStudent(
            $payment->student_id !== null ? (int) $payment->student_id : null,
            FinanceActivityNotification::TYPE_PAYMENT_EXPIRED,
            $title,
            $body,
            [
                'reference' => $payment->payment_number.':expired',
                'payment_id' => (int) $payment->id,
                'invoice_id' => $payment->invoice_id !== null ? (int) $payment->invoice_id : null,
                'amount' => (float) $payment->amount,
            ],
        );
    }

    /**
     * Dana dilaporkan masuk pada pembayaran yang sudah ditutup (§3, §29).
     *
     * Scheduler mengekspire kanal gateway yang batas waktunya lewat. Bila provider
     * kemudian mengirim `paid` untuk baris yang sama, dananya nyata tetapi
     * dokumennya sudah mati — dan jurnal tidak boleh dipaksa masuk lewat jalur
     * yang salah. Yang terjadi adalah melaporkan: petugas pemegang hak verifikasi
     * yang memutuskan rekonsiliasi atau refund resmi.
     */
    public function paymentLateCapture(FinancePayment $payment): int
    {
        [$title, $body] = $this->templates->render('payment_late_capture', $this->paymentData($payment) + [
            'status_label' => FinancePayment::STATUS_LABELS[$payment->status] ?? $payment->status,
        ]);

        return $this->notifyStaff(
            'finance.payment.verify',
            FinanceActivityNotification::TYPE_PAYMENT_PENDING_VERIFICATION,
            $title,
            $body,
            [
                'reference' => $payment->payment_number.':late-capture',
                'payment_id' => (int) $payment->id,
                'invoice_id' => $payment->invoice_id !== null ? (int) $payment->invoice_id : null,
                'amount' => (float) $payment->amount,
            ],
        );
    }

    /** Invoice lewat jatuh tempo → ingatkan santri + wali. */
    public function invoiceOverdue(FinanceInvoice $invoice): int
    {
        if ($invoice->student_id === null) {
            return 0;
        }

        [$title, $body] = $this->templates->render('billing_overdue', $this->invoiceData($invoice) + [
            'amount_text' => self::money((float) $invoice->remaining_amount),
        ]);

        return $this->notifyStudent(
            (int) $invoice->student_id,
            FinanceActivityNotification::TYPE_BILLING_OVERDUE,
            $title,
            $body,
            [
                'reference' => $invoice->invoice_number.':overdue',
                'invoice_id' => (int) $invoice->id,
                'amount' => (float) $invoice->remaining_amount,
            ],
        );
    }

    /**
     * Satu anak tangga pengingat (§7): `$offset` hari relatif terhadap jatuh
     * tempo — negatif sebelum, positif sesudah. Referensinya memuat bilangan
     * tangganya, jadi H-3 tidak menelan H-7 dan satu tangga tidak terkirim dua
     * kali walau scheduler berjalan berulang.
     *
     * Tangga pasca-jatuh-tempo sengaja memakai `collection_reminder`, bukan
     * `billing_overdue`: tipe terakhir adalah penanda perpindahan status yang
     * dikirim `markOverdue()` sekali per invoice. Kalau tangga ikut memakainya,
     * wali membaca dua pesan "lewat jatuh tempo" untuk invoice yang sama dan
     * pengingat harian tidak lagi bisa dibedakan dari penanda status.
     */
    public function invoiceReminder(FinanceInvoice $invoice, int $offset): int
    {
        if ($invoice->student_id === null) {
            return 0;
        }

        // Tiga anak tangga, tiga kalimat: "hari lagi", "hari ini", dan "sudah
        // lewat" tidak bisa ditulis dengan satu template.
        $key = $offset > 0
            ? 'collection_reminder_after'
            : ($offset === 0 ? 'collection_reminder_today' : 'collection_reminder_before');

        [$title, $body] = $this->templates->render($key, $this->invoiceData($invoice) + [
            'amount_text' => self::money((float) $invoice->remaining_amount),
            'days' => abs($offset),
        ]);

        return $this->notifyStudent(
            (int) $invoice->student_id,
            FinanceActivityNotification::TYPE_COLLECTION_REMINDER,
            $title,
            $body,
            [
                'reference' => $invoice->invoice_number.':reminder:'.sprintf('%+d', $offset),
                'invoice_id' => (int) $invoice->id,
                'amount' => (float) $invoice->remaining_amount,
                'due_date' => $invoice->due_date?->toDateString(),
                'reminder_offset' => $offset,
            ],
        );
    }

    /**
     * Satu anak tangga pengingat angsuran (§7, §8).
     *
     * Pengingat tagihan biasa (`invoiceReminder`) tidak cukup di sini: rencana
     * pembayaran memecah satu invoice menjadi beberapa tanggal jatuh tempo, dan
     * yang ditagih ke wali adalah angsuran yang berikutnya — bukan sisa invoice
     * seluruhnya. Tipe pesannya ikut dibagi dua (`payment_plan_due` sebelum H,
     * `payment_plan_overdue` sesudahnya) karena arahnya berbeda: yang satu
     * meminta uang disiapkan, yang satu menagih yang tertunda.
     *
     * Referensinya memuat nomor angsuran dan bilangan tangganya, jadi angsuran 2
     * tidak menelan angsuran 3 dan satu anak tangga tidak terkirim dua kali walau
     * scheduler berjalan berulang.
     */
    public function installmentReminder(FinancePaymentPlanInstallment $installment, int $offset): int
    {
        $plan = $installment->plan;

        if ($plan === null || $plan->student_id === null) {
            return 0;
        }

        $type = $offset > 0
            ? FinanceActivityNotification::TYPE_PAYMENT_PLAN_OVERDUE
            : FinanceActivityNotification::TYPE_PAYMENT_PLAN_DUE;

        $key = $offset > 0
            ? 'installment_overdue'
            : ($offset === 0 ? 'installment_due_today' : 'installment_due_before');

        $due = $installment->due_date?->toDateString() ?? '-';

        [$title, $body] = $this->templates->render($key, [
            'student_name' => $this->studentName((int) $plan->student_id),
            'plan_number' => (string) $plan->plan_number,
            'installment_sequence' => (int) $installment->sequence,
            'amount_text' => self::money((float) $installment->outstanding_amount),
            'due_date' => $due,
            'days' => abs($offset),
            'invoice_number' => (string) ($plan->invoice?->invoice_number ?? '-'),
        ]);

        return $this->notifyStudent(
            (int) $plan->student_id,
            $type,
            $title,
            $body,
            [
                'reference' => $plan->plan_number.':'.$installment->sequence.':'.sprintf('%+d', $offset),
                'payment_plan_id' => (int) $plan->id,
                'installment_id' => (int) $installment->id,
                'installment_sequence' => (int) $installment->sequence,
                'invoice_id' => $plan->invoice_id !== null ? (int) $plan->invoice_id : null,
                'amount' => (float) $installment->outstanding_amount,
                'due_date' => $due,
                'reminder_offset' => $offset,
            ],
        );
    }

    /** Daftar ulang diterbitkan tagihannya. */
    public function reRegistrationBilled(FinanceReRegistration $registration): int
    {
        $invoice = $registration->invoice;

        [$title, $body] = $this->templates->render('re_registration_billed', $this->reRegistrationData($registration) + [
            'academic_year' => $registration->nextAcademicYear?->name ?? 'tahun ajaran baru',
            'amount_text' => self::money((float) $registration->amount - (float) $registration->discount_amount),
        ]);

        return $this->notifyStudent(
            (int) $registration->student_id,
            FinanceActivityNotification::TYPE_RE_REGISTRATION_BILLED,
            $title,
            $body,
            [
                // Nomor registrasi dipakai ulang saat daftar ulang diterbitkan
                // lagi (cancel → generate), jadi invoice ikut membedakan.
                'reference' => $registration->registration_number.':'.$invoice?->id,
                're_registration_id' => (int) $registration->id,
                'invoice_id' => $invoice?->id !== null ? (int) $invoice->id : null,
                'amount' => (float) $registration->amount,
                'due_date' => $registration->due_date?->toDateString(),
            ],
        );
    }

    /** Daftar ulang selesai (tahun ajaran aktif). */
    public function reRegistrationCompleted(FinanceReRegistration $registration): int
    {
        [$title, $body] = $this->templates->render('re_registration_completed', $this->reRegistrationData($registration) + [
            'academic_year' => $registration->nextAcademicYear?->name ?? '-',
        ]);

        return $this->notifyStudent(
            (int) $registration->student_id,
            FinanceActivityNotification::TYPE_RE_REGISTRATION_COMPLETED,
            $title,
            $body,
            [
                'reference' => $registration->registration_number.':completed',
                're_registration_id' => (int) $registration->id,
            ],
        );
    }

    /** Batas waktu daftar ulang lewat. */
    public function reRegistrationExpired(FinanceReRegistration $registration): int
    {
        [$title, $body] = $this->templates->render('re_registration_expired', $this->reRegistrationData($registration));

        return $this->notifyStudent(
            (int) $registration->student_id,
            FinanceActivityNotification::TYPE_RE_REGISTRATION_EXPIRED,
            $title,
            $body,
            [
                'reference' => $registration->registration_number.':expired',
                're_registration_id' => (int) $registration->id,
            ],
        );
    }

    /**
     * Refund disetujui — keputusan selesai, uang belum bergerak.
     *
     * Wali perlu tahu sejak sini: nominal yang disetujui bisa lebih kecil dari
     * yang diajukan, dan penolakan masih mungkin terjadi sebelum `complete()`.
     */
    public function refundApproved(FinanceRefund $refund): int
    {
        return $this->notifyRefund($refund, FinanceActivityNotification::TYPE_REFUND_APPROVED, 'refund_approved');
    }

    /** Refund selesai — jurnal balik terposting dan uang sudah keluar (§25). */
    public function refundCompleted(FinanceRefund $refund): int
    {
        return $this->notifyRefund($refund, FinanceActivityNotification::TYPE_REFUND_COMPLETED, 'refund_completed');
    }

    /**
     * Refund tidak punya santri sendiri — peneritanya adalah pembayaran yang
     * dikembalikan, dan itu satu-satunya jejak siapa yang berhak tahu.
     */
    private function notifyRefund(FinanceRefund $refund, string $type, string $templateKey): int
    {
        $payment = $refund->payment;

        [$title, $body] = $this->templates->render($templateKey, [
            'student_name' => $this->studentName($payment?->student_id !== null ? (int) $payment->student_id : null),
            'refund_number' => (string) $refund->refund_number,
            'payment_number' => (string) ($payment?->payment_number ?? '-'),
            'amount_text' => self::money((float) $refund->amount),
            'reason' => $refund->reason,
        ]);

        return $this->notifyStudent(
            $payment?->student_id !== null ? (int) $payment->student_id : null,
            $type,
            $title,
            $body,
            [
                'reference' => $refund->refund_number.($type === FinanceActivityNotification::TYPE_REFUND_COMPLETED ? ':completed' : ':approved'),
                'refund_id' => (int) $refund->id,
                'payment_id' => $payment?->id !== null ? (int) $payment->id : null,
                'invoice_id' => $payment?->invoice_id !== null ? (int) $payment->invoice_id : null,
                'amount' => (float) $refund->amount,
            ],
        );
    }

    // ==================== Kotak masuk ====================

    public function listFor(User $user, int $perPage = 20, bool $unreadOnly = false): LengthAwarePaginator
    {
        return $user->notifications()
            ->where('type', FinanceActivityNotification::class)
            ->when($unreadOnly, fn (Builder $q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function totalFor(User $user): int
    {
        return (int) $user->notifications()
            ->where('type', FinanceActivityNotification::class)
            ->count();
    }

    public function unreadCount(User $user): int
    {
        return (int) $user->unreadNotifications()
            ->where('type', FinanceActivityNotification::class)
            ->count();
    }

    public function markAsRead(User $user, string $id): bool
    {
        $notification = $user->notifications()
            ->where('type', FinanceActivityNotification::class)
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
            ->where('type', FinanceActivityNotification::class)
            ->update(['read_at' => Carbon::now()]);
    }

    // ==================== Internal ====================

    /**
     * Nilai token yang tersedia untuk kalimat tagihan/invoice. Angka mentah
     * (`amount`) tetap milik payload kotak masuk; template hanya membaca yang
     * sudah berbentuk uang.
     *
     * @return array<string, mixed>
     */
    private function invoiceData(FinanceInvoice $invoice): array
    {
        return [
            'invoice_number' => (string) $invoice->invoice_number,
            'due_date' => $invoice->due_date?->toDateString() ?? '-',
            'student_name' => $this->studentName($invoice->student_id !== null ? (int) $invoice->student_id : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentData(FinancePayment $payment): array
    {
        return [
            'payment_number' => (string) $payment->payment_number,
            'amount_text' => self::money((float) $payment->amount),
            'invoice_number' => (string) ($payment->invoice?->invoice_number ?? '-'),
            'student_name' => $this->studentName($payment->student_id !== null ? (int) $payment->student_id : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reRegistrationData(FinanceReRegistration $registration): array
    {
        return [
            'registration_number' => (string) $registration->registration_number,
            'due_date' => $registration->due_date?->toDateString() ?? '-',
            'student_name' => $this->studentName((int) $registration->student_id),
        ];
    }

    /** Nama santri untuk token `{student_name}`; satu query per santri per jalan. */
    private function studentName(?int $studentId): string
    {
        if ($studentId === null) {
            return '-';
        }

        return $this->studentNames[$studentId]
            ??= (string) (Student::query()->whereKey($studentId)->value('full_name') ?? '-');
    }

    /**
     * Akun yang berhak melihat tagihan seorang santri: santri itu sendiri lewat
     * `users.userable`, lalu walinya lewat akun PPDB bertaut `student_guardians`.
     * Relasi difilter lewat pivot sehingga wali hanya menerima anaknya sendiri.
     *
     * @return list<int>
     */
    protected function recipientsOf(?int $studentId): array
    {
        if ($studentId === null) {
            return [];
        }

        $ids = [];

        $studentUser = User::query()
            ->where('userable_type', Student::class)
            ->where('userable_id', $studentId)
            ->pluck('id');

        foreach ($studentUser as $id) {
            $ids[] = (int) $id;
        }

        $guardianIds = DB::table('student_guardians')
            ->where('student_id', $studentId)
            ->pluck('guardian_id')
            ->unique();

        if ($guardianIds->isNotEmpty()) {
            $parentUsers = PpdbParentAccount::query()
                ->whereIn('guardian_id', $guardianIds)
                ->whereNotNull('user_id')
                ->pluck('user_id');

            foreach ($parentUsers as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Sudah pernah dikirim belum notifikasi jenis ini untuk dokumen ini?
     * Referensi dokumen disimpan pada `context.reference`.
     *
     * @param  array<string, mixed>  $context
     */
    protected function shouldSend(int $userId, string $type, array $context): bool
    {
        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('type', FinanceActivityNotification::class)
            ->where('data->type', $type);

        $reference = $context['reference'] ?? null;

        if ($reference !== null) {
            $query->where('data->reference', (string) $reference);
        }

        return ! $query->exists();
    }

    private static function money(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
