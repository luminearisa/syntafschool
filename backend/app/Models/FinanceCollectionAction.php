<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Aksi penagihan (§6): satu baris = satu tindakan atas satu invoice outstanding.
 *
 * Jenis aksi diambil apa adanya dari daftar §6 (reminder, phone call, WhatsApp,
 * email, parent notification, manual follow-up, payment plan, waiver request,
 * write-off request) supaya yang tercatat di sejarah sama dengan yang dibaca di
 * spesifikasi — bukan kategori hasil rekayasa yang membuat laporan penagihan
 * perlu kamus sendiri.
 *
 * Model ini tidak punya hubungan ke angka piutang. `notified_count` hanya
 * menyatakan berapa notifikasi in-app yang benar-benar terkirim untuk aksi ini
 * (deduplikasi bisa menahannya), bukan berapa yang "seharusnya" dibayar.
 */
class FinanceCollectionAction extends BaseModel
{
    use LogsFinanceActivity;

    public const TYPE_REMINDER = 'reminder';

    public const TYPE_PHONE_CALL = 'phone_call';

    public const TYPE_WHATSAPP = 'whatsapp';

    public const TYPE_EMAIL = 'email';

    public const TYPE_PARENT_NOTIFICATION = 'parent_notification';

    public const TYPE_MANUAL_FOLLOW_UP = 'manual_follow_up';

    public const TYPE_PAYMENT_PLAN = 'payment_plan';

    public const TYPE_WAIVER_REQUEST = 'waiver_request';

    public const TYPE_WRITE_OFF_REQUEST = 'write_off_request';

    /** §6: jenis aksi yang boleh dicatat halaman collection. */
    public const TYPES = [
        self::TYPE_REMINDER,
        self::TYPE_PHONE_CALL,
        self::TYPE_WHATSAPP,
        self::TYPE_EMAIL,
        self::TYPE_PARENT_NOTIFICATION,
        self::TYPE_MANUAL_FOLLOW_UP,
        self::TYPE_PAYMENT_PLAN,
        self::TYPE_WAIVER_REQUEST,
        self::TYPE_WRITE_OFF_REQUEST,
    ];

    public const TYPE_LABELS = [
        self::TYPE_REMINDER => 'Pengingat',
        self::TYPE_PHONE_CALL => 'Panggilan Telepon',
        self::TYPE_WHATSAPP => 'WhatsApp',
        self::TYPE_EMAIL => 'Email',
        self::TYPE_PARENT_NOTIFICATION => 'Pemberitahuan Orang Tua',
        self::TYPE_MANUAL_FOLLOW_UP => 'Tindak Lanjut Manual',
        self::TYPE_PAYMENT_PLAN => 'Rencana Pembayaran',
        self::TYPE_WAIVER_REQUEST => 'Permintaan Penghapusan Sebagian',
        self::TYPE_WRITE_OFF_REQUEST => 'Permintaan Write-off',
    ];

    /**
     * Aksi yang salurannya benar-benar tersedia di sistem: dikirim lewat engine
     * notifikasi in-app yang sudah ada, dan hasil pengirimannya ikut tercatat.
     * Kanal lain (telepon, WhatsApp, kunjungan) adalah tindakan manusia — sejarah
     * aksinya yang disimpan, bukan pesannya.
     */
    public const NOTIFIABLE_TYPES = [
        self::TYPE_REMINDER,
        self::TYPE_EMAIL,
        self::TYPE_PARENT_NOTIFICATION,
    ];

    /** Aksi yang melahirkan dokumen lain dan karena itu harus menautkannya. */
    public const DOCUMENT_TYPES = [
        self::TYPE_PAYMENT_PLAN,
        self::TYPE_WAIVER_REQUEST,
        self::TYPE_WRITE_OFF_REQUEST,
    ];

    /**
     * Kelas yang boleh ditautkan sebagai dokumen hasil aksi. Daftar ini pemisah
     * antara "tautan bebas" dan "tautan yang bisa ditelusuri": menambahkan jenis
     * dokumen baru berarti menambah di sini sekaligus cara memverifikasinya,
     * bukan menerima morph sembarang dari klien. Setiap kelas yang disebut di
     * sini wajib punya `documentNumber()` supaya sejarah bisa menyebut nomornya.
     */
    public const REFERENCE_TYPES = [
        FinanceAdjustment::class,
        FinancePaymentPlan::class,
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'invoice_id' => 'integer',
        'reference_id' => 'integer',
        'notified_count' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Dokumen yang lahir dari aksi ini (write-off/waiver → adjustment, cicilan → plan). */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->action_type] ?? (string) $this->action_type;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_COLLECTION_ACTION';
    }
}
