<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi modul Keuangan.
 *
 * Memakai sistem notifikasi bawaan Laravel — tidak ada notification engine baru
 * dan tidak menduplikasi tabel notifikasi modul lain. Kotak masuk in-app
 * (`database`) selalu menyala; email adalah kanal tambahan yang sekolah nyalakan
 * sendiri (`notification_email_enabled`) dan hanya untuk surat yang memang
 * menunggu jawaban keluarga (lihat `MAILED_TYPES`).
 *
 * Kanal WhatsApp (§8) sengaja tidak ada: §8 mensyaratkannya "jika provider
 * tersedia", dan aplikasi ini tidak punya penyedia WhatsApp — tanpa gateway,
 * tanpa kredensial, dan tanpa nomor tujuan terverifikasi sebuah pesan tidak bisa
 * dikirim. Menawarkan kanal itu hanya menghasilkan surat yang tercatat gagal
 * tanpa pernah ada.
 *
 * Daftar kanal dihitung oleh `FinanceNotificationService` dan diterima lewat
 * konstruktor, bukan dibaca sendiri di dalam kelas ini: notifikasi harus tetap
 * benda kecil yang bisa dirender dan diuji tanpa ikut menarik konfigurasi.
 *
 * Sengaja TIDAK mengimplementasikan `ShouldQueue` walau trait `Queueable` ikut
 * dipakai: `FinanceNotificationService` harus tahu apakah suratnya benar-benar
 * berangkat — ia menghitung jumlah terkirim, menahan duplikat lewat
 * `context.reference`, dan mencatat kegagalan kanal ke register operasi (§29).
 * Notifikasi yang di-queue selalu mengembalikan "terkirim" sebelum ada yang
 * membaca error-nya, jadi ketiga hal itu menjadi bohong. Yang di-queue adalah
 * pekerjaan berat yang *mengirimkannya* (job pengingat & penjadwalan), bukan
 * suratnya sendiri.
 */
class FinanceActivityNotification extends Notification
{
    use Queueable;

    public const TYPE_INVOICE_ISSUED = 'invoice_issued';

    public const TYPE_PAYMENT_RECEIVED = 'payment_received';

    public const TYPE_PAYMENT_PENDING_VERIFICATION = 'payment_pending_verification';

    public const TYPE_PAYMENT_REJECTED = 'payment_rejected';

    /**
     * Gagal di sisi provider (webhook mengirim `failed`), bukan ditolak petugas:
     * uang tidak pernah masuk dan wali perlu tahu kanal mana yang mati, bukan
     * bahwa dokumennya dinilai salah.
     */
    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_PAYMENT_CANCELLED = 'payment_cancelled';

    public const TYPE_PAYMENT_EXPIRED = 'payment_expired';

    public const TYPE_BILLING_OVERDUE = 'billing_overdue';

    public const TYPE_COLLECTION_REMINDER = 'collection_reminder';

    public const TYPE_RE_REGISTRATION_BILLED = 're_registration_billed';

    public const TYPE_RE_REGISTRATION_COMPLETED = 're_registration_completed';

    public const TYPE_RE_REGISTRATION_EXPIRED = 're_registration_expired';

    /**
     * Angsuran sebuah rencana pembayaran menuju atau melewati jatuh temponya.
     * Tangga sebelum-H-nya (`payment_plan_due`) dan sesudahnya
     * (`payment_plan_overdue`) dipisah karena pesannya berbeda arah: yang satu
     * meminta uang disiapkan, yang satu menagih yang tertunda.
     */
    public const TYPE_PAYMENT_PLAN_DUE = 'payment_plan_due';

    public const TYPE_PAYMENT_PLAN_OVERDUE = 'payment_plan_overdue';

    /** Refund disetujui (belum ada uang keluar) dan diselesaikan (uang keluar). */
    public const TYPE_REFUND_APPROVED = 'refund_approved';

    public const TYPE_REFUND_COMPLETED = 'refund_completed';

    /**
     * Tipe yang layak menjadi email.
     *
     * Isinya surat keluarga — dokumen yang menagih atau menjawab keluarga dan
     * menunggu mereka melakukan sesuatu. Dua tipe petugas (`payment_pending_verification`
     * dan penangkap dana gateway yang masuk terlambat) sengaja tidak ikut: itu
     * antrean kerja di dalam layar, dan mengirimnya ke email berarti mengirim
     * satu surat untuk setiap pembayaran manual ke setiap pemegang hak verifikasi
     * — sampai 20 orang per transaksi.
     */
    public const MAILED_TYPES = [
        self::TYPE_INVOICE_ISSUED,
        self::TYPE_PAYMENT_RECEIVED,
        self::TYPE_PAYMENT_REJECTED,
        self::TYPE_PAYMENT_FAILED,
        self::TYPE_PAYMENT_CANCELLED,
        self::TYPE_PAYMENT_EXPIRED,
        self::TYPE_BILLING_OVERDUE,
        self::TYPE_COLLECTION_REMINDER,
        self::TYPE_RE_REGISTRATION_BILLED,
        self::TYPE_RE_REGISTRATION_COMPLETED,
        self::TYPE_RE_REGISTRATION_EXPIRED,
        self::TYPE_PAYMENT_PLAN_DUE,
        self::TYPE_PAYMENT_PLAN_OVERDUE,
        self::TYPE_REFUND_APPROVED,
        self::TYPE_REFUND_COMPLETED,
    ];

    public static function isMailed(string $type): bool
    {
        return in_array($type, self::MAILED_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $context = [],
        public array $channels = ['database'],
    ) {}

    /**
     * Kanal yang benar-benar bisa dipakai untuk akun ini. Email butuh alamat:
     * akun wali tanpa email tidak membuat suratnya gagal, hanya tidak kebagian
     * salinan — kotak masuk tetap menerima.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (in_array('mail', $this->channels, true) && filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Salinan email dari surat yang sama: judul jadi subject, isi tetap satu
     * paragraf yang dibaca orang tuanya di kotak masuk. Tidak ada template
     * kedua — kalau isinya berbeda di dua kanal, tidak ada yang bisa dipercaya
     * ketika wali dan petugas berbeda cerita.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->greeting('Yth. '.($notifiable->name ?? 'Wali').',')
            ->line($this->body);

        $reference = $this->context['reference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            $message->line('Referensi: '.$reference);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
        ] + $this->context;
    }
}
