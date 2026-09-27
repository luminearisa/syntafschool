<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu operasi Keuangan: pekerjaan yang di-queue (§28) atau peristiwa gagal yang
 * tercatat di jalur nyata (§29).
 *
 * Model ini adalah log itu sendiri, karena itu ia sengaja TIDAK memakai trait
 * `LogsFinanceActivity`: menuliskan jejak audit ke register jejak audit hanya
 * akan menghasilkan baris kedua yang menjelaskan baris pertama, dan menggandakan
 * jumlah "kejadian" di laporan yang sama.
 *
 * `kind` membedakan keduanya:
 * - `job` punya kemajuan (total_rows/processed_rows) dan `payload` untuk dijalankan
 *   ulang; statusnya Queued → Processing → Completed/Failed (§28).
 * - `incident` adalah satu kegagalan yang terjadi pada dokumen nyata (webhook
 *   ditolak, charge gateway gagal, notifikasi gagal, scheduler gagal). Ia tidak
 *   punya kemajuan dan tidak bisa "dispatch ulang" — yang bisa diulang adalah
 *   pekerjaan aslinya, dan itu sebabnya `retryable` pada incident berarti
 *   "jalurnya idempoten, silakan ulangi aksinya".
 *
 * Angka uang tidak ada di tabel ini. Operasi tidak mengubah piutang, kas, atau
 * jurnal; ia hanya menceritakan apa yang terjadi pada dokumen-dokumen itu.
 */
class FinanceOperation extends BaseModel
{
    public const KIND_JOB = 'job';

    public const KIND_INCIDENT = 'incident';

    public const KIND_LABELS = [
        self::KIND_JOB => 'Pekerjaan',
        self::KIND_INCIDENT => 'Peristiwa gagal',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'Mengantre',
        self::STATUS_PROCESSING => 'Berjalan',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_FAILED => 'Gagal',
    ];

    // ---- Pekerjaan yang boleh di-queue (§28) ----

    public const JOB_SPP_GENERATE = 'spp.generate';

    public const JOB_BILLING_GENERATE = 'billing.generate';

    public const JOB_INVOICE_ISSUE = 'invoice.issue';

    public const JOB_REMINDERS_SEND = 'reminders.send';

    public const JOB_PAYMENTS_EXPIRE = 'payments.expire';

    public const JOB_DAILY_MAINTENANCE = 'daily.maintenance';

    public const JOBS = [
        self::JOB_SPP_GENERATE,
        self::JOB_BILLING_GENERATE,
        self::JOB_INVOICE_ISSUE,
        self::JOB_REMINDERS_SEND,
        self::JOB_PAYMENTS_EXPIRE,
        self::JOB_DAILY_MAINTENANCE,
    ];

    public const JOB_LABELS = [
        self::JOB_SPP_GENERATE => 'Generate SPP terjadwal',
        self::JOB_BILLING_GENERATE => 'Generate billing massal',
        self::JOB_INVOICE_ISSUE => 'Terbitkan invoice massal',
        self::JOB_REMINDERS_SEND => 'Kirim pengingat jatuh tempo',
        self::JOB_PAYMENTS_EXPIRE => 'Kedaluwarsakan pembayaran kanal',
        self::JOB_DAILY_MAINTENANCE => 'Pemeliharaan harian Keuangan',
    ];

    // ---- Peristiwa yang dicatat untuk observability (§29) ----

    public const EVENT_GATEWAY_CHARGE = 'gateway.charge';

    public const EVENT_GATEWAY_WEBHOOK = 'gateway.webhook';

    public const EVENT_NOTIFICATION_SEND = 'notification.send';

    public const EVENT_SCHEDULED_JOB = 'scheduled.job';

    public const EVENT_JOURNAL_POSTING = 'journal.posting';

    public const EVENT_LABELS = [
        self::EVENT_GATEWAY_CHARGE => 'Charge gateway gagal',
        self::EVENT_GATEWAY_WEBHOOK => 'Notifikasi gateway gagal',
        self::EVENT_NOTIFICATION_SEND => 'Pengiriman notifikasi gagal',
        self::EVENT_SCHEDULED_JOB => 'Tugas terjadwal gagal',
        self::EVENT_JOURNAL_POSTING => 'Penerbitan jurnal gagal',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'context' => 'array',
        'retryable' => 'boolean',
        'attempts' => 'integer',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'duration_ms' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeJobs(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_JOB);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING]);
    }

    public function isRunning(): bool
    {
        return in_array((string) $this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function nameLabel(): string
    {
        // Nama pekerjaan dan nama peristiwa gagal ada di kolom yang sama tetapi
        // kamusnya berbeda; tanpa gabungan ini incident terbaca sebagai kode mentah.
        return self::JOB_LABELS[$this->name] ?? self::EVENT_LABELS[$this->name] ?? (string) $this->name;
    }

    /**
     * Progres 0–100. Tanpa total_rows pekerjaan tetap punya arti (mis. scheduler
     * harian), jadi bagiannya hanya dihitung bila penyebutnya ada.
     */
    public function progressPercent(): ?int
    {
        if ($this->total_rows === null || $this->total_rows <= 0) {
            return null;
        }

        return (int) min(100, floor(((int) $this->processed_rows / (int) $this->total_rows) * 100));
    }
}
