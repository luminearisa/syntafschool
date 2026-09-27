<?php

namespace App\Services\Finance;

use App\Models\FinanceClearance;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceRefund;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keberesan finansial / financial clearance (§12 Phase 5).
 *
 * ## Alur §12 dijalankan apa adanya
 *
 * Keberesan diminta → periksa penagihan → periksa piutang → periksa pembayaran
 * yang belum terverifikasi → periksa refund yang belum selesai → periksa deposit
 * → hasilnya satu status. Tidak ada langkah yang menghitung uang dengan caranya
 * sendiri: penagihan dan piutang dibaca dari kolom invoice yang dipelihara mesin
 * pembayaran, baris penghalang piutang diproduksi `FinancialEligibilityService`
 * (jadi layar §11 dan §12 menjawab dengan angka yang sama), deposit dari
 * `FinanceReceivableService::creditBalances()`, dan pembayaran/refund dari
 * tabel dokumennya masing-masing.
 *
 * ## Barangsiapa menahan apa
 *
 * Tingkat ketahanan sebuah keperluan TIDAK dikodekan di sini. Keperluan §12
 * dipetakan ke aksi §11 (`ACTION_FOR_PURPOSE`), dan aksi itulah yang aturan,
 * basis, masa tenggang, serta ambang nominalnya sudah bisa diatur sekolah lewat
 * Settings. Kalau sekolah meloloskan ijazah dengan tunggakan, ia mengubah satu
 * kunci konfigurasi — bukan menunggu service ini diedit.
 *
 * ## Tidak ada angka yang bergerak
 *
 * Kelas ini tidak membuat invoice, tidak menerima pembayaran, tidak menghapus
 * piutang, dan tidak mem-posting jurnal — tidak pula saat CLEARED, BLOCKED,
 * APPROVED, atau WAIVED. WAIVED hanya berarti "layanan diberikan meski ada
 * tanggungan"; bila sekolah sungguh membebaskan tanggungan itu, jalurnya tetap
 * write-off piutang (§21) atau diskon, yang punya jurnal dan persetujuannya
 * sendiri. Karena itu keputusan keberesan tidak pernah meninggalkan jejak di
 * buku besar, dan membacanya bolak-balik tidak pernah mengubah apa pun.
 */
class FinanceClearanceService
{
    /**
     * Keperluan §12 → aksi §11 yang memimpin keputusannya.
     *
     * Lulus, pindah, keluar, dan penyelesaian akhir adalah satu kelas yang
     * sama: santri berhenti, jadi uang yang belum selesai berhenti bersamanya —
     * semuanya mengikat pada `graduation_clearance` yang bawaannya menahan.
     * Certificate dan daftar ulang sudah punya aksinya sendiri di §11.
     */
    public const ACTION_FOR_PURPOSE = [
        FinanceClearance::PURPOSE_GRADUATION => 'graduation_clearance',
        FinanceClearance::PURPOSE_TRANSFER => 'graduation_clearance',
        FinanceClearance::PURPOSE_WITHDRAWAL => 'graduation_clearance',
        FinanceClearance::PURPOSE_FINAL_SETTLEMENT => 'graduation_clearance',
        FinanceClearance::PURPOSE_CERTIFICATE => 'certificate',
        FinanceClearance::PURPOSE_RE_REGISTRATION => 're_registration',
    ];

    /**
     * Empat hasil pemeriksaan satu langkah daftar periksa. Hanya `blocking`
     * yang menahan keberesan; `attention` wajib dilaporkan walau tidak
     * menghalangi, dan `skipped` dipakai bila kebijakan memetiknya.
     */
    public const RESULT_OK = 'ok';

    public const RESULT_ATTENTION = 'attention';

    public const RESULT_BLOCKING = 'blocking';

    public const RESULT_SKIPPED = 'skipped';

    /**
     * Baris rinci per langkah: cukup untuk layar, dan mencegah daftar periksa
     * satu santri dengan ribuan tagihan menjadi bom memori.
     */
    private const LINE_LIMIT = 20;

    public function __construct(
        private readonly FinanceSettingsService $settings,
        private readonly FinanceNumberingService $numbering,
        private readonly FinancialEligibilityService $eligibility,
        private readonly FinanceReceivableService $receivables,
    ) {}

    /** Nilai konfigurasi yang dibutuhkan UI (§12: dua kunci `clearance_*`). */
    public function config(): array
    {
        return [
            'enabled' => $this->eligibility->enabled(),
            'purposes' => FinanceClearance::PURPOSES,
            'purpose_labels' => FinanceClearance::PURPOSE_LABELS,
            'statuses' => FinanceClearance::STATUSES,
            'status_labels' => FinanceClearance::STATUS_LABELS,
            'action_for_purpose' => self::ACTION_FOR_PURPOSE,
            'block_on_pending_payment' => $this->blockOnPendingPayment(),
            'block_on_open_refund' => $this->blockOnOpenRefund(),
        ];
    }

    /**
     * Daftar keberesan (§12): filter santri/keperluan/status/tanggal + pencarian.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): Builder
    {
        return FinanceClearance::query()
            ->with(['student:id,full_name,nis', 'requestedBy:id,name', 'decidedBy:id,name'])
            ->when(filled($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['purpose'] ?? null), fn (Builder $q) => $q->where('purpose', (string) $filters['purpose']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', (string) $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $q) => $q->whereDate('evaluated_on', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $q) => $q->whereDate('evaluated_on', '<=', $filters['date_to']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(fn (Builder $inner) => $inner
                    ->where('clearance_number', 'like', "%$term%")
                    ->orWhereHas('student', fn (Builder $s) => $s
                        ->where('full_name', 'like', "%$term%")
                        ->orWhere('nis', 'like', "%$term%")));
            })
            ->latest('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        return $this->query($filters)->paginate($perPage);
    }

    /**
     * Buat — atau perbarui — keberesan satu santri untuk satu keperluan.
     *
     * Permintaan kedua atas pasangan santri+keperluan yang sama TIDAK membuat
     * dokumen kedua selama yang pertama belum diputuskan: ia diisi ulang dengan
     * pemeriksaan terbaru. Itu yang membuat tombol "ajukan" aman ditekan dua
     * kali (§6 idempotensi) dan yang menjaga layar keberesan tetap berisi satu
     * baris per persoalan, bukan enam percobaan.
     *
     * @param  array{student_id: int|string, purpose: string, notes?: string|null}  $data
     */
    public function request(array $data, ?User $actor = null): FinanceClearance
    {
        $studentId = (int) ($data['student_id'] ?? 0);
        $purpose = (string) ($data['purpose'] ?? '');
        $notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;

        $this->assertPurpose($purpose);

        $student = Student::query()->whereKey($studentId)->first(['id']);

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }

        return DB::transaction(function () use ($student, $purpose, $notes, $actor): FinanceClearance {
            $existing = FinanceClearance::query()
                ->where('student_id', $student->id)
                ->where('purpose', $purpose)
                ->open()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing !== null) {
                if ($notes !== null) {
                    $existing->notes = $notes;
                }

                return $this->apply($existing, $this->evaluate($student->id, $purpose, Carbon::now()));
            }

            $clearance = new FinanceClearance([
                'clearance_number' => $this->numbering->next(FinanceNumberingService::CODE_CLEARANCE),
                'student_id' => (int) $student->id,
                'purpose' => $purpose,
                'status' => FinanceClearance::STATUS_PENDING,
                'notes' => $notes,
                'requested_by' => $actor?->id,
                'requested_at' => Carbon::now(),
            ]);

            $clearance->evaluated_on = Carbon::today();
            $clearance->checklist = [];
            $clearance->save();

            return $this->apply($clearance, $this->evaluate((int) $student->id, $purpose, Carbon::now()));
        });
    }

    /**
     * Isi ulang pemeriksaan dari dokumen yang ada sekarang.
     *
     * Hanya keberesan yang belum diputuskan yang boleh bergerak: dua status
     * keputusan membeku supaya alasan penyetujuannya tetap terbaca seperti
     * semula walau tagihan santri berubah sesudah itu.
     */
    public function refresh(FinanceClearance $clearance, ?Carbon $asOf = null): FinanceClearance
    {
        return DB::transaction(function () use ($clearance, $asOf): FinanceClearance {
            $locked = $this->lockOpen($clearance);

            return $this->apply($locked, $this->evaluate(
                (int) $locked->student_id,
                (string) $locked->purpose,
                $asOf ?? Carbon::now(),
            ));
        });
    }

    /**
     * Putusan "boleh" atas keberesan yang menunggu persetujuan (§12: APPROVED).
     *
     * Tanggungannya tidak hilang — hanya keputusannya diambil, dengan nama dan
     * tanggal. Pelunasan tetap menunggu di AR dan penagihan (§6) tetap berjalan.
     */
    public function approve(FinanceClearance $clearance, string $reason, ?User $actor = null): FinanceClearance
    {
        return $this->decide($clearance, FinanceClearance::STATUS_APPROVED, $reason, $actor);
    }

    /**
     * Pengecualian (§12: WAIVED): layanan diberikan walau aturannya menahan.
     *
     * Ini bukan penghapusan piutang. Tidak satu pun nominal bergerak di sini;
     * yang berubah hanya jawaban atas "boleh dilayani atau tidak". Sekolah yang
     * sungguh ingin menghapus tunggakan memakai write-off (§21) atau diskon,
     * yang punya jurnal dan persetujuannya sendiri.
     */
    public function waive(FinanceClearance $clearance, string $reason, ?User $actor = null): FinanceClearance
    {
        return $this->decide($clearance, FinanceClearance::STATUS_WAIVED, $reason, $actor);
    }

    /**
     * Jawaban hidup "apakah santri ini sudah beres untuk keperluan X".
     *
     * Dibaca TANPA menulis apa pun: pemanggilnya adalah modul akademik atau
     * operasional yang sedang berada di tengah transaksinya sendiri, dan Finance
     * tidak berhak melahirkan dokumen di dalam transaksi orang lain.
     *
     * Keputusan terakhir yang tercatat menang atas pemeriksaan hidup. Kalau
     * sekolah sudah mengecualikan santri ini untuk ijazahnya, modul pemanggil
     * tidak boleh menahan ijazah itu hanya karena tagihan baru masuk sesudah
     * keputusan itu — yang berubah cukup dicatat sebagai pengecualian baru.
     *
     * @return array<string, mixed>
     */
    public function verdict(Student|int $student, string $purpose, ?Carbon $asOf = null): array
    {
        $studentId = $student instanceof Student ? (int) $student->id : (int) $student;

        $this->assertPurpose($purpose);

        $record = FinanceClearance::query()
            ->where('student_id', $studentId)
            ->where('purpose', $purpose)
            ->with('decidedBy:id,name')
            ->latest('id')
            ->first();

        $evaluation = $this->evaluate($studentId, $purpose, $asOf);

        $evaluation['clearance_id'] = $record?->id;
        $evaluation['clearance_number'] = $record?->clearance_number;
        $evaluation['decided_at'] = $record?->decided_at?->toIso8601String();
        $evaluation['decided_by_name'] = $record?->decidedBy?->name;
        $evaluation['decision_reason'] = $record?->decision_reason;
        $evaluation['settled'] = $record !== null && ! $record->isOpen()
            ? $record->isSettled()
            : $evaluation['status'] === FinanceClearance::STATUS_CLEARED;

        if ($record !== null && ! $record->isOpen()) {
            // Status yang dilaporkan adalah status yang berlaku: keputusan tercatat
            // tidak dibuka kembali oleh pemeriksaan ulang.
            $evaluation['status'] = $record->status;
        }

        return $evaluation;
    }

    /**
     * Gerbang untuk modul pemanggil: belum beres → permintaannya gagal.
     *
     * Yang gagal adalah transaksi PEMANGGIL, bukan baris santrinya: Finance tidak
     * pernah mengubah status akademik (§11) dan tidak pernah mengubah isi
     * keberesan di sini.
     *
     * @return array<string, mixed>
     */
    public function assertCleared(Student|int $student, string $purpose, ?Carbon $asOf = null): array
    {
        $verdict = $this->verdict($student, $purpose, $asOf);

        if (! $verdict['settled']) {
            throw ValidationException::withMessages([
                'financial_clearance' => $verdict['headline'],
            ]);
        }

        return $verdict;
    }

    /**
     * Satu langkah daftar periksa §12, dihitung ulang dari dokumen yang ada,
     * tanpa menyentuh satu baris pun. `verdict()` dan `request()` memakainya;
     * layar "periksa dulu" cukup menjawab lewat `verdict()`.
     *
     * @return array<string, mixed>
     */
    public function evaluate(int $studentId, string $purpose, ?Carbon $asOf = null): array
    {
        $this->assertPurpose($purpose);

        $now = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $action = self::ACTION_FOR_PURPOSE[$purpose];
        $rule = $this->eligibility->ruleFor($action);

        $billing = $this->billingCheck($studentId);
        // Piutang ikut aturan aksi §11; pembayaran tertunda dan refund ikut
        // kuncinya sendiri (§12); penagihan dan deposit selalu hanya dilaporkan.
        $receivable = $this->receivableCheck($studentId, $action, $now, $rule);
        $pending = $this->pendingPaymentCheck($studentId);
        $refund = $this->openRefundCheck($studentId);
        $credit = $this->creditCheck($studentId);

        $items = [$billing, $receivable, $pending, $refund, $credit];

        $blocking = array_values(array_filter(
            $items,
            fn (array $item): bool => $item['result'] === self::RESULT_BLOCKING,
        ));

        if ($blocking === []) {
            $status = FinanceClearance::STATUS_CLEARED;
        } elseif ($rule === FinancialEligibilityService::RULE_APPROVE) {
            $status = FinanceClearance::STATUS_PENDING;
        } else {
            $status = FinanceClearance::STATUS_BLOCKED;
        }

        return [
            'purpose' => $purpose,
            'purpose_label' => FinanceClearance::PURPOSE_LABELS[$purpose],
            'action' => $action,
            'rule' => $rule,
            'rule_label' => FinancialEligibilityService::RULE_LABELS[$rule],
            'evaluated_on' => $now->toDateString(),
            'status' => $status,
            'outstanding_total' => $receivable['amount'],
            'credit_total' => $credit['amount'],
            'blocking_count' => (int) array_sum(array_column($items, 'blocking_count')),
            'checklist' => $items,
            'headline' => $this->headlineFor($status, $purpose, $blocking),
        ];
    }

    // ==================== Pemeriksaan §12 ====================

    /**
     * Check Billing: seberapa besar yang pernah ditagihkan ke santri ini.
     *
     * Angka ini hanya menjelaskan latar — tagihan yang belum jatuh tempo bukan
     * kesalahan siapa pun — jadi ia tidak pernah berstatus menahan.
     *
     * @return array<string, mixed>
     */
    private function billingCheck(int $studentId): array
    {
        $row = FinanceInvoice::query()
            ->where('student_id', $studentId)
            ->whereNotIn('status', [FinanceInvoice::STATUS_DRAFT, FinanceInvoice::STATUS_CANCELLED])
            ->selectRaw('COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as billing_total, COALESCE(SUM(paid_amount), 0) as paid_total, COALESCE(SUM(written_off_amount), 0) as written_off_total')
            ->first();

        $billingTotal = round((float) ($row->billing_total ?? 0), 2);
        $paidTotal = round((float) ($row->paid_total ?? 0), 2);

        return [
            'key' => 'billing',
            'label' => 'Penagihan',
            'result' => self::RESULT_OK,
            'count' => (int) ($row->invoice_count ?? 0),
            'amount' => $billingTotal,
            'blocking_count' => 0,
            'paid_amount' => $paidTotal,
            'written_off_amount' => round((float) ($row->written_off_total ?? 0), 2),
            'remaining_amount' => round($billingTotal - $paidTotal, 2),
            'detail' => 'Total yang pernah ditagihkan dan yang sudah masuk.',
        ];
    }

    /**
     * Check AR: baris piutang yang menahan, hasil hitung §11 (nomor invoice,
     * keterangan, nominal, jatuh tempo, sisa, status) — §12 menuntut rinciannya,
     * bukan tulisan "Belum Lunas".
     *
     * @return array<string, mixed>
     */
    private function receivableCheck(int $studentId, string $action, Carbon $now, string $rule): array
    {
        $blocking = $this->eligibility->blocking($studentId, $action, $now);

        if (! $this->eligibility->enabled()) {
            return [
                'key' => 'receivable',
                'label' => 'Piutang / tunggakan',
                'result' => self::RESULT_SKIPPED,
                'count' => count($blocking['lines']),
                'amount' => $blocking['total'],
                'blocking_count' => 0,
                'basis' => $blocking['basis'],
                'grace_days' => $blocking['grace_days'],
                'min_outstanding' => $blocking['min_outstanding'],
                'lines' => [],
                'detail' => 'Pemeriksaan kelayakan finansial sedang dimatikan di Settings, sehingga tunggakan tidak menahan layanan.',
            ];
        }

        // Di bawah ambang minimum sekolah (atau nihil), sisa tagihan tidak
        // material: dilaporkan tetapi tidak menahan.
        $holds = $blocking['material'] && $rule !== FinancialEligibilityService::RULE_ALLOW;

        return [
            'key' => 'receivable',
            'label' => 'Piutang / tunggakan',
            'result' => $holds ? self::RESULT_BLOCKING : ($blocking['material'] ? self::RESULT_ATTENTION : self::RESULT_OK),
            'count' => count($blocking['lines']),
            'amount' => $blocking['total'],
            'blocking_count' => $holds ? count($blocking['lines']) : 0,
            'basis' => $blocking['basis'],
            'grace_days' => $blocking['grace_days'],
            'min_outstanding' => $blocking['min_outstanding'],
            'overdue_total' => $blocking['outstanding']['total_overdue'],
            'lines' => $blocking['lines'],
            'detail' => $holds
                ? 'Tunggakan ini menahan keberesan sesuai aturan aksi "'.$action.'".'
                : ($blocking['material']
                    ? 'Aturan aksi tidak menahan layanan meski ada tunggakan.'
                    : 'Tidak ada tunggakan yang menghalangi.'),
        ];
    }

    /**
     * Check Pending Payment: pembayaran yang belum diverifikasi (§24).
     *
     * Uangnya belum masuk kas dan belum ber-jurnal, jadi sekolah yang ketat
     * tidak meloloskan keberesan di atas angka yang masih bisa hilang; sekolah
     * yang longgar menganggapnya cukup dengan status `attention`.
     *
     * @return array<string, mixed>
     */
    private function pendingPaymentCheck(int $studentId): array
    {
        $holds = $this->blockOnPendingPayment();

        $payments = FinancePayment::query()
            ->where('student_id', $studentId)
            ->where('status', FinancePayment::STATUS_PENDING)
            ->orderBy('created_at')
            ->limit(self::LINE_LIMIT)
            ->get(['id', 'payment_number', 'amount', 'method', 'created_at']);

        $total = round((float) FinancePayment::query()
            ->where('student_id', $studentId)
            ->where('status', FinancePayment::STATUS_PENDING)
            ->sum('amount'), 2);

        $count = $payments->count();

        return [
            'key' => 'pending_payment',
            'label' => 'Pembayaran menunggu verifikasi',
            'result' => $count === 0 ? self::RESULT_OK : ($holds ? self::RESULT_BLOCKING : self::RESULT_ATTENTION),
            'count' => $count,
            'amount' => $total,
            'blocking_count' => $holds ? $count : 0,
            'lines' => $payments->map(fn (FinancePayment $payment): array => [
                'reference' => $payment->payment_number,
                'amount' => round((float) $payment->amount, 2),
                'method' => $payment->method,
                'date' => $payment->created_at?->toIso8601String(),
                'status' => $payment->status,
                'status_label' => FinancePayment::STATUS_LABELS[$payment->status] ?? $payment->status,
            ])->all(),
            'detail' => $holds
                ? 'Verifikasi pembayaran lebih dulu agar uangnya menjadi fakta di kas.'
                : 'Dilaporkan saja: sekolah tidak menahan keberesan atas pembayaran yang belum terverifikasi.',
        ];
    }

    /**
     * Check Refund: hak santri yang masih dalam proses pengembalian.
     *
     * Untuk santri yang benar-benar pergi, uang keluar yang belum selesai harus
     * tuntas lebih dulu — kalau tidak, keberesan tertutup sementara sekolah masih
     * berutang langkah.
     *
     * @return array<string, mixed>
     */
    private function openRefundCheck(int $studentId): array
    {
        $holds = $this->blockOnOpenRefund();

        $open = [
            FinanceRefund::STATUS_REQUESTED,
            FinanceRefund::STATUS_APPROVED,
            FinanceRefund::STATUS_PROCESSING,
        ];

        $refunds = FinanceRefund::query()
            ->join('finance_payments', 'finance_payments.id', '=', 'finance_refunds.payment_id')
            ->where('finance_payments.student_id', $studentId)
            ->whereIn('finance_refunds.status', $open)
            ->orderBy('finance_refunds.id')
            ->limit(self::LINE_LIMIT)
            ->get(['finance_refunds.id', 'finance_refunds.refund_number', 'finance_refunds.amount', 'finance_refunds.status', 'finance_refunds.created_at']);

        $total = round((float) FinanceRefund::query()
            ->join('finance_payments', 'finance_payments.id', '=', 'finance_refunds.payment_id')
            ->where('finance_payments.student_id', $studentId)
            ->whereIn('finance_refunds.status', $open)
            ->sum('finance_refunds.amount'), 2);

        $count = $refunds->count();

        return [
            'key' => 'refund',
            'label' => 'Refund belum selesai',
            'result' => $count === 0 ? self::RESULT_OK : ($holds ? self::RESULT_BLOCKING : self::RESULT_ATTENTION),
            'count' => $count,
            'amount' => $total,
            'blocking_count' => $holds ? $count : 0,
            'lines' => $refunds->map(fn (FinanceRefund $refund): array => [
                'reference' => $refund->refund_number,
                'amount' => round((float) $refund->amount, 2),
                'date' => $refund->created_at?->toIso8601String(),
                'status' => $refund->status,
                'status_label' => FinanceRefund::STATUS_LABELS[$refund->status] ?? $refund->status,
            ])->all(),
            'detail' => $holds
                ? 'Selesaikan pengembalian hak santri lebih dulu.'
                : 'Dilaporkan saja: sekolah tidak menahan keberesan atas refund yang belum selesai.',
        ];
    }

    /**
     * Check Credit: deposit santri (§19). Tidak pernah menahan — ini uang sekolah
     * yang masih menjadi hak santri, jadi ia wajib DILAPORKAN supaya diselesaikan
     * (dikembalikan atau dipindahkan), bukan dijadikan alasan penolakan.
     *
     * @return array<string, mixed>
     */
    private function creditCheck(int $studentId): array
    {
        $credit = $this->receivables->creditBalance($studentId);

        return [
            'key' => 'credit',
            'label' => 'Deposit / kelebihan bayar',
            'result' => $credit > FinanceClearance::EPSILON ? self::RESULT_ATTENTION : self::RESULT_OK,
            'count' => $credit > FinanceClearance::EPSILON ? 1 : 0,
            'amount' => $credit,
            'blocking_count' => 0,
            'detail' => 'Masih menjadi hak santri: kembalikan atau pindahkan sebelum berkas benar-benar tertutup.',
        ];
    }

    // ==================== Internal ====================

    private function decide(FinanceClearance $clearance, string $status, string $reason, ?User $actor): FinanceClearance
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan keputusan wajib diisi supaya pengecualian bisa dipertanggungjawabkan.',
            ]);
        }

        return DB::transaction(function () use ($clearance, $status, $reason, $actor): FinanceClearance {
            $locked = FinanceClearance::query()->whereKey($clearance->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [FinanceClearance::STATUS_PENDING, FinanceClearance::STATUS_BLOCKED], true)) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === FinanceClearance::STATUS_CLEARED
                        ? 'Keberesan ini sudah bersih sehingga tidak perlu diputuskan.'
                        : 'Keberesan ini sudah pernah diputuskan dan tidak dapat dibuka kembali.',
                ]);
            }

            // Pemeriksaan terakhir diisi ulang sebelum keputusan ditempelkan, jadi
            // alasan penyetujuannya selalu menyebut angka pada hari keputusan diambil.
            $fresh = $this->apply(
                $locked,
                $this->evaluate((int) $locked->student_id, (string) $locked->purpose, Carbon::now()),
            );

            // Tanggungannya bisa saja lunas di antara dua klik. Kalau begitu tidak
            // ada yang perlu dikecualikan atau disetujui lagi.
            if ($fresh->status === FinanceClearance::STATUS_CLEARED) {
                throw ValidationException::withMessages([
                    'status' => 'Keberesan ini baru saja menjadi bersih sehingga tidak perlu diputuskan.',
                ]);
            }

            FinanceStatusMachine::assert($fresh, $status, 'status');

            $fresh->status = $status;
            $fresh->decided_by = $actor?->id;
            $fresh->decided_at = Carbon::now();
            $fresh->decision_reason = $reason;
            $fresh->save();

            // Relasi penyetuju dimuat SEBELUM kolomnya ditulis (oleh `apply()`),
            // jadi ia dibaca ulang — kalau tidak, respons keputusan mengembalikan
            // `decided_by: null` padahal keputusannya sudah tersimpan.
            $fresh->load('decidedBy:id,name');

            return $fresh;
        });
    }

    /**
     * Tulis hasil pemeriksaan ke baris keberesan.
     *
     * Status, angka ringkasan, `headline`, dan `checklist` selalu ditulis satu
     * paket: kolom ringkasan ada supaya daftar bisa diurutkan di SQL, dan
     * memisahkannya dari checklist hanya melahirkan dua jawaban atas pertanyaan
     * yang sama.
     */
    private function apply(FinanceClearance $clearance, array $evaluation): FinanceClearance
    {
        FinanceStatusMachine::assert($clearance, $evaluation['status'], 'status');

        $clearance->status = $evaluation['status'];
        $clearance->evaluated_on = Carbon::parse($evaluation['evaluated_on']);
        $clearance->checklist = $evaluation['checklist'];
        $clearance->headline = $evaluation['headline'];
        $clearance->outstanding_total = $evaluation['outstanding_total'];
        $clearance->credit_total = $evaluation['credit_total'];
        $clearance->blocking_count = $evaluation['blocking_count'];
        $clearance->save();

        $clearance->loadMissing(['student', 'requestedBy', 'decidedBy']);

        return $clearance;
    }

    private function lockOpen(FinanceClearance $clearance): FinanceClearance
    {
        $locked = FinanceClearance::query()->whereKey($clearance->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'Keberesan yang sudah diputuskan tidak dapat diperiksa ulang.',
            ]);
        }

        return $locked;
    }

    private function assertPurpose(string $purpose): void
    {
        if (! array_key_exists($purpose, self::ACTION_FOR_PURPOSE)) {
            throw ValidationException::withMessages([
                'purpose' => 'Keperluan keberesan tidak dikenal: '.$purpose,
            ]);
        }
    }

    /**
     * Satu kalimat "kenapa belum boleh", berisi nominal dan jumlah dokumennya —
     * rinciannya ada di `checklist` (§12).
     *
     * @param  list<array<string, mixed>>  $blocking
     */
    private function headlineFor(string $status, string $purpose, array $blocking): string
    {
        $label = FinanceClearance::PURPOSE_LABELS[$purpose] ?? $purpose;

        if ($blocking === []) {
            return $label.' tidak tertahan oleh keuangan sekolah.';
        }

        $symbol = (string) $this->settings->get('currency_symbol', 'Rp');

        $parts = array_map(fn (array $item): string => $item['label'].' '.$this->money((float) $item['amount'], $symbol), $blocking);
        $prefix = $status === FinanceClearance::STATUS_BLOCKED ? 'Keberesan ditahan' : 'Keberesan menunggu keputusan';

        return $prefix.' untuk '.$label.': '.implode('; ', $parts).'.';
    }

    private function money(float $amount, string $symbol): string
    {
        return $symbol.' '.number_format($amount, 0, ',', '.');
    }

    private function blockOnPendingPayment(): bool
    {
        return (bool) $this->settings->get('clearance_block_on_pending_payment', true);
    }

    private function blockOnOpenRefund(): bool
    {
        return (bool) $this->settings->get('clearance_block_on_open_refund', true);
    }
}
