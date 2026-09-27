<?php

namespace App\Services\Finance;

use App\Models\FinanceAdjustment;
use App\Models\FinanceBilling;
use App\Models\FinanceBudget;
use App\Models\FinanceCashierSession;
use App\Models\FinanceClearance;
use App\Models\FinanceExpenseRequest;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePeriod;
use App\Models\FinanceReconciliation;
use App\Models\FinanceRefund;
use App\Models\FinanceTransfer;
use App\Models\FinanceVendorInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Satu tabel transisi status untuk seluruh dokumen Finance (Phase 5 §6).
 *
 * Sebelum kelas ini ada, setiap service menulis penjaga statusnya sendiri:
 * bentuknya mirip (`if ($fresh->status !== X) throw ...`) tapi kuncinya,
 * pesannya, dan — yang penting — daftar status sumber yang sah berbeda-beda per
 * file. Akibatnya tidak ada satu pun tempat yang bisa menjawab "dokumen ini
 * boleh pindah ke mana saja", dan perpindahan liar seperti PAID → PENDING
 * hanya ketahuan kalau kebetulan ada yang membaca service-nya.
 *
 * Aturan bakunya di sini:
 *  1. Hukum perpindahan status hanya tinggal satu daftar. Service tidak lagi
 *     menuliskan kondisi statusnya sendiri — ia bertanya ke mesin ini.
 *  2. Mundur dari status final (mis. `paid → issued`) hanya sah melalui jalur
 *     koreksi resmi: pembatalan payment (§29), refund (§25), atau adjustment
 *     yang diposting (§51). Tabel mengizinkan perpindahan itu HANYA bila
 *     pembalikannya memang sudah terjadi, dan pemanggilnya adalah service
 *     pembalik tersebut.
 *  3. Sebuah status boleh menjadi penerus dirinya sendiri hanya bila dokumen
 *     itu memang sah disentuh dua kali (review bertingkat §16, cicilan AP).
 *     Untuk perpindahan satu-kali tidak ada self-loop, jadi permintaan kedua
 *     gagal dengan sendirinya — termasuk payment yang sudah lunas.
 *  4. Status yang dihitung dari nominal atau tanggal bukan keputusan workflow,
 *     jadi ia tidak lewat sini. `FinanceOutstandingRecalculator` (invoice/billing),
 *     `FinanceBillingService::markOverdue()` (jatuh tempo),
 *     `FinancePaymentPlanService::sync()` (proyeksi cicilan), dan
 *     `FinanceRefundService::refreshPaymentStatus()` (status payment dari total
 *     refund) adalah penulis turunan; tabel ini mencatat perpindahan yang
 *     mereka hasilkan supaya bisa diperiksa, bukan supaya mereka memanggil
 *     `assert()`.
 *
 * Kunci pesan error mengikuti pemanggil (`$field`): frontend dan test sudah
 * membaca field spesifik (`status`, `payment`, `refund`, `session`), jadi
 * memusatkan aturan tidak boleh mengubah bentuk responsnya.
 */
class FinanceStatusMachine
{
    /**
     * Status asal => status tujuan yang sah. Status tanpa entri adalah final.
     *
     * @var array<class-string, array<string, list<string>>>
     */
    public const TRANSITIONS = [
        FinancePayment::class => [
            FinancePayment::STATUS_PENDING => [
                FinancePayment::STATUS_PAID,
                FinancePayment::STATUS_FAILED,
                FinancePayment::STATUS_EXPIRED,
            ],
            FinancePayment::STATUS_PAID => [
                FinancePayment::STATUS_CANCELLED,
                // §25/§29: refund sebagian menyisakan pembayaran yang masih sah.
                FinancePayment::STATUS_PARTIAL,
                FinancePayment::STATUS_REFUNDED,
            ],
            FinancePayment::STATUS_PARTIAL => [
                FinancePayment::STATUS_CANCELLED,
                FinancePayment::STATUS_REFUNDED,
            ],
            FinancePayment::STATUS_FAILED => [],
            FinancePayment::STATUS_EXPIRED => [],
            FinancePayment::STATUS_CANCELLED => [],
            FinancePayment::STATUS_REFUNDED => [],
        ],

        FinanceRefund::class => [
            FinanceRefund::STATUS_REQUESTED => [
                FinanceRefund::STATUS_APPROVED,
                FinanceRefund::STATUS_REJECTED,
            ],
            FinanceRefund::STATUS_APPROVED => [
                FinanceRefund::STATUS_PROCESSING,
                FinanceRefund::STATUS_COMPLETED,
                FinanceRefund::STATUS_REJECTED,
            ],
            FinanceRefund::STATUS_PROCESSING => [
                FinanceRefund::STATUS_COMPLETED,
                FinanceRefund::STATUS_FAILED,
            ],
            FinanceRefund::STATUS_COMPLETED => [],
            FinanceRefund::STATUS_REJECTED => [],
            FinanceRefund::STATUS_FAILED => [],
        ],

        FinanceTransfer::class => [
            FinanceTransfer::STATUS_DRAFT => [
                FinanceTransfer::STATUS_SUBMITTED,
                FinanceTransfer::STATUS_CANCELLED,
            ],
            FinanceTransfer::STATUS_SUBMITTED => [
                FinanceTransfer::STATUS_APPROVED,
                FinanceTransfer::STATUS_REJECTED,
                FinanceTransfer::STATUS_CANCELLED,
            ],
            FinanceTransfer::STATUS_APPROVED => [
                FinanceTransfer::STATUS_COMPLETED,
                FinanceTransfer::STATUS_CANCELLED,
            ],
            FinanceTransfer::STATUS_REJECTED => [],
            FinanceTransfer::STATUS_COMPLETED => [],
            FinanceTransfer::STATUS_CANCELLED => [],
        ],

        FinanceAdjustment::class => [
            FinanceAdjustment::STATUS_DRAFT => [FinanceAdjustment::STATUS_SUBMITTED],
            FinanceAdjustment::STATUS_SUBMITTED => [
                FinanceAdjustment::STATUS_APPROVED,
                FinanceAdjustment::STATUS_REJECTED,
            ],
            FinanceAdjustment::STATUS_APPROVED => [FinanceAdjustment::STATUS_POSTED],
            FinanceAdjustment::STATUS_REJECTED => [],
            FinanceAdjustment::STATUS_POSTED => [],
        ],

        FinanceBudget::class => [
            FinanceBudget::STATUS_DRAFT => [
                FinanceBudget::STATUS_SUBMITTED,
                FinanceBudget::STATUS_CANCELLED,
            ],
            FinanceBudget::STATUS_SUBMITTED => [
                FinanceBudget::STATUS_APPROVED,
                FinanceBudget::STATUS_ACTIVE,
                FinanceBudget::STATUS_REJECTED,
                FinanceBudget::STATUS_CANCELLED,
            ],
            FinanceBudget::STATUS_APPROVED => [FinanceBudget::STATUS_ACTIVE, FinanceBudget::STATUS_CLOSED],
            FinanceBudget::STATUS_ACTIVE => [FinanceBudget::STATUS_CLOSED],
            // Anggaran yang ditolak boleh diperbaiki lalu diajukan ulang.
            FinanceBudget::STATUS_REJECTED => [
                FinanceBudget::STATUS_SUBMITTED,
                FinanceBudget::STATUS_CANCELLED,
            ],
            FinanceBudget::STATUS_CLOSED => [],
            FinanceBudget::STATUS_CANCELLED => [],
        ],

        FinanceExpenseRequest::class => [
            FinanceExpenseRequest::STATUS_DRAFT => [
                FinanceExpenseRequest::STATUS_SUBMITTED,
                FinanceExpenseRequest::STATUS_CANCELLED,
            ],
            FinanceExpenseRequest::STATUS_SUBMITTED => [
                FinanceExpenseRequest::STATUS_UNDER_REVIEW,
                FinanceExpenseRequest::STATUS_APPROVED,
                FinanceExpenseRequest::STATUS_REJECTED,
                FinanceExpenseRequest::STATUS_CANCELLED,
            ],
            // Matriks approval berjenjang (§16): persetujuan bertingkat yang belum
            // sampai ke penyetuju akhir menahan dokumen tetap pada under_review.
            FinanceExpenseRequest::STATUS_UNDER_REVIEW => [
                FinanceExpenseRequest::STATUS_UNDER_REVIEW,
                FinanceExpenseRequest::STATUS_APPROVED,
                FinanceExpenseRequest::STATUS_REJECTED,
                FinanceExpenseRequest::STATUS_CANCELLED,
            ],
            FinanceExpenseRequest::STATUS_APPROVED => [
                FinanceExpenseRequest::STATUS_PROCESSING,
                FinanceExpenseRequest::STATUS_CANCELLED,
            ],
            FinanceExpenseRequest::STATUS_REJECTED => [
                FinanceExpenseRequest::STATUS_SUBMITTED,
                FinanceExpenseRequest::STATUS_CANCELLED,
            ],
            FinanceExpenseRequest::STATUS_PROCESSING => [FinanceExpenseRequest::STATUS_PAID],
            FinanceExpenseRequest::STATUS_PAID => [],
            FinanceExpenseRequest::STATUS_CANCELLED => [],
        ],

        FinanceVendorInvoice::class => [
            FinanceVendorInvoice::STATUS_DRAFT => [
                FinanceVendorInvoice::STATUS_RECEIVED,
                FinanceVendorInvoice::STATUS_CANCELLED,
            ],
            FinanceVendorInvoice::STATUS_RECEIVED => [
                FinanceVendorInvoice::STATUS_PARTIALLY_PAID,
                FinanceVendorInvoice::STATUS_PAID,
                FinanceVendorInvoice::STATUS_OVERDUE,
                FinanceVendorInvoice::STATUS_CANCELLED,
            ],
            FinanceVendorInvoice::STATUS_OVERDUE => [
                FinanceVendorInvoice::STATUS_PARTIALLY_PAID,
                FinanceVendorInvoice::STATUS_PAID,
                FinanceVendorInvoice::STATUS_CANCELLED,
            ],
            // Self-loop: dicicil dua kali tetap `partially_paid` (§43) — yang
            // dicegah mesin adalah melompat dari lunas kembali ke sebagian.
            FinanceVendorInvoice::STATUS_PARTIALLY_PAID => [
                FinanceVendorInvoice::STATUS_PARTIALLY_PAID,
                FinanceVendorInvoice::STATUS_PAID,
            ],
            FinanceVendorInvoice::STATUS_PAID => [],
            FinanceVendorInvoice::STATUS_CANCELLED => [],
        ],

        FinanceInvoice::class => self::BILLING_LIKE,
        FinanceBilling::class => self::BILLING_LIKE,

        FinancePeriod::class => [
            FinancePeriod::STATUS_OPEN => [FinancePeriod::STATUS_CLOSED],
            // §49: periode yang keliru ditutup adalah koreksi terkontrol, bukan
            // pembatalan transaksi — transaksi tetap utuh, hanya wadahnya dibuka.
            FinancePeriod::STATUS_CLOSED => [FinancePeriod::STATUS_OPEN],
        ],

        FinanceReconciliation::class => [
            FinanceReconciliation::STATUS_IN_PROGRESS => [
                FinanceReconciliation::STATUS_RECONCILED,
                FinanceReconciliation::STATUS_DISCREPANCY,
            ],
            FinanceReconciliation::STATUS_RECONCILED => [],
            FinanceReconciliation::STATUS_DISCREPANCY => [],
        ],

        FinanceCashierSession::class => [
            FinanceCashierSession::STATUS_OPEN => [FinanceCashierSession::STATUS_CLOSED],
            FinanceCashierSession::STATUS_CLOSED => [FinanceCashierSession::STATUS_REVIEWED],
            FinanceCashierSession::STATUS_REVIEWED => [],
        ],

        FinanceClearance::class => [
            // §12: tiga status pemeriksaan boleh saling menggantikan — termasuk
            // dirinya sendiri, karena keberesan memang sah diperiksa ulang —
            // sebab potret piutangnya dibaca ulang setiap kali dokumen keuangan
            // bergerak: "ditahan" hari ini menjadi "bebas" besok tanpa ada yang
            // memutuskan apa pun. Dua status keputusan (SETUJU/KECUALI) final:
            // alasan seorang pemimpin meloloskan santri harus tetap terbaca
            // persis seperti saat ia diberikan.
            FinanceClearance::STATUS_PENDING => self::CLEARANCE_OPEN,
            FinanceClearance::STATUS_CLEARED => self::CLEARANCE_OPEN,
            FinanceClearance::STATUS_BLOCKED => self::CLEARANCE_OPEN,
            FinanceClearance::STATUS_APPROVED => [],
            FinanceClearance::STATUS_WAIVED => [],
        ],
    ];

    /**
     * Status tujuan yang masih terbuka bagi keberesan yang belum diputuskan:
     * tiga status pemeriksaan (dengan self-loop) + dua jalur keputusan.
     *
     * @var list<string>
     */
    private const CLEARANCE_OPEN = [
        FinanceClearance::STATUS_PENDING,
        FinanceClearance::STATUS_CLEARED,
        FinanceClearance::STATUS_BLOCKED,
        FinanceClearance::STATUS_APPROVED,
        FinanceClearance::STATUS_WAIVED,
    ];

    /**
     * Invoice dan billing memakai siklus yang sama: tagihan lahir draft, jadi
     * sah ditagih, lalu lunas/sebagian lewat pembayaran, atau batal lewat
     * pembatalan billing (§15).
     *
     * @var array<string, list<string>>
     */
    private const BILLING_LIKE = [
        FinanceInvoice::STATUS_DRAFT => [
            FinanceInvoice::STATUS_ISSUED,
            FinanceInvoice::STATUS_CANCELLED,
        ],
        FinanceInvoice::STATUS_ISSUED => [
            FinanceInvoice::STATUS_PARTIAL,
            FinanceInvoice::STATUS_PAID,
            FinanceInvoice::STATUS_OVERDUE,
            FinanceInvoice::STATUS_CANCELLED,
        ],
        FinanceInvoice::STATUS_PARTIAL => [
            FinanceInvoice::STATUS_PAID,
            FinanceInvoice::STATUS_OVERDUE,
        ],
        FinanceInvoice::STATUS_OVERDUE => [
            FinanceInvoice::STATUS_PARTIAL,
            FinanceInvoice::STATUS_PAID,
        ],
        // Hanya bisa dicapai lewat pembalikan: payment dibatalkan/refund
        // diselesaikan, lalu recalculator menarik `paid_amount` dan sisa naik
        // lagi. Tidak ada endpoint yang menurunkannya langsung.
        FinanceInvoice::STATUS_PAID => [
            FinanceInvoice::STATUS_PARTIAL,
            FinanceInvoice::STATUS_ISSUED,
        ],
        FinanceInvoice::STATUS_CANCELLED => [],
    ];

    /**
     * Status tujuan yang sah dari status sekarang. Array kosong = final.
     *
     * @return list<string>
     */
    public static function allowed(string $modelClass, ?string $from): array
    {
        return self::TRANSITIONS[$modelClass][$from] ?? [];
    }

    public static function can(Model $document, string $to): bool
    {
        return in_array($to, self::allowed($document::class, (string) $document->status), true);
    }

    /**
     * Pastikan perpindahan $document->status → $to sah; kalau tidak, gagal
     * lebih dulu sebelum ada nominal, jurnal, atau notifikasi yang bergerak.
     *
     * @throws ValidationException
     */
    public static function assert(Model $document, string $to, string $field = 'status'): void
    {
        $from = (string) $document->status;

        if (! in_array($to, self::allowed($document::class, $from), true)) {
            throw ValidationException::withMessages([
                $field => 'Perubahan status '.self::label($document, $from).' ke '
                    .self::label($document, $to).' tidak diizinkan.',
            ]);
        }
    }

    private static function label(Model $document, string $status): string
    {
        $labels = defined($document::class.'::STATUS_LABELS') ? $document::STATUS_LABELS : [];

        return '['.($labels[$status] ?? $status).']';
    }
}
