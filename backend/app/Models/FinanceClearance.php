<?php

namespace App\Models;

use App\Models\Concerns\LogsFinanceActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keberesan finansial satu santri untuk satu keperluan (§12 Phase 5).
 *
 * Baris ini adalah dokumen keputusan, bukan catatan uang: seluruh nominalnya
 * (`outstanding_total`, `credit_total`, isi `checklist`) adalah potret dari
 * invoice, payment, refund, dan alokasi pada `evaluated_on`. Membayar
 * tunggakan tidak menyunting baris ini — ia menghasilkan invoice/payment yang
 * lalu dibaca ulang oleh `FinanceClearanceService::refresh()`.
 *
 * Dua status terakhir (WAIVED, APPROVED) adalah keputusan manusia yang tercatat
 * dengan siapa dan alasannya. Keduanya membekukan potret pemeriksaan: alasan
 * persetujuan harus tetap bisa dibaca persis seperti saat ia diberikan, walau
 * tagihan santri bergerak sesudah itu.
 */
class FinanceClearance extends BaseModel
{
    use LogsFinanceActivity;

    public const PURPOSE_GRADUATION = 'graduation';

    public const PURPOSE_TRANSFER = 'transfer';

    public const PURPOSE_WITHDRAWAL = 'withdrawal';

    public const PURPOSE_CERTIFICATE = 'certificate';

    public const PURPOSE_RE_REGISTRATION = 're_registration';

    public const PURPOSE_FINAL_SETTLEMENT = 'final_settlement';

    /** §12: enam keperluan yang memakai keberesan finansial. */
    public const PURPOSES = [
        self::PURPOSE_GRADUATION,
        self::PURPOSE_TRANSFER,
        self::PURPOSE_WITHDRAWAL,
        self::PURPOSE_CERTIFICATE,
        self::PURPOSE_RE_REGISTRATION,
        self::PURPOSE_FINAL_SETTLEMENT,
    ];

    public const PURPOSE_LABELS = [
        self::PURPOSE_GRADUATION => 'Kelulusan',
        self::PURPOSE_TRANSFER => 'Pindah sekolah',
        self::PURPOSE_WITHDRAWAL => 'Keluar / mengundurkan diri',
        self::PURPOSE_CERTIFICATE => 'Penerbitan sertifikat / ijazah',
        self::PURPOSE_RE_REGISTRATION => 'Daftar ulang',
        self::PURPOSE_FINAL_SETTLEMENT => 'Penyelesaian akhir',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_APPROVED = 'approved';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_CLEARED, self::STATUS_BLOCKED,
        self::STATUS_WAIVED, self::STATUS_APPROVED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu keputusan',
        self::STATUS_CLEARED => 'Bebas finansial',
        self::STATUS_BLOCKED => 'Ditahan',
        self::STATUS_WAIVED => 'Dikecualikan',
        self::STATUS_APPROVED => 'Disetujui',
    ];

    /** Status yang masih bisa diperiksa ulang dan masih bisa diputuskan. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING, self::STATUS_CLEARED, self::STATUS_BLOCKED,
    ];

    /** Toleransi pembulatan rupiah-per-sen; di bawah ini dianggap nol. */
    public const EPSILON = 0.0001;

    protected $casts = [
        'student_id' => 'integer',
        'outstanding_total' => 'decimal:2',
        'credit_total' => 'decimal:2',
        'blocking_count' => 'integer',
        'evaluated_on' => 'date',
        'checklist' => 'array',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Keberesan yang belum menjadi keputusan final. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Santri boleh dilayani: tidak ada tanggungan, atau sudah dikecualikan. */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_CLEARED, self::STATUS_WAIVED, self::STATUS_APPROVED], true);
    }

    public function purposeLabel(): string
    {
        return self::PURPOSE_LABELS[$this->purpose] ?? (string) $this->purpose;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Baris tagihan yang menahan keberesan ini (§12: "Show Outstanding"). */
    public function blockingLines(): array
    {
        foreach ((array) $this->checklist as $item) {
            if (($item['key'] ?? null) === 'receivable') {
                return $item['lines'] ?? [];
            }
        }

        return [];
    }

    public function documentNumber(): string
    {
        return (string) $this->clearance_number;
    }

    public function activityEventPrefix(): string
    {
        return 'FINANCE_CLEARANCE';
    }
}
