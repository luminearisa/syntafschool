<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\FinanceInvoice;
use App\Models\PpdbParentAccount;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Portal orang tua untuk Keuangan (§9) + ringkasan finansial santri (§10).
 *
 * ## Kenapa tidak memakai permission finance
 *
 * Orang tua login lewat alur PPDB yang sudah ada dan tidak memegang permission
 * admin apa pun (§24), jadi route portal cukup `auth:sanctum`. Keamanannya
 * bukan permission melainkan KEPEMILIKAN: tiap endpoint lebih dulu menjalankan
 * `assertChild()`, dan rantai kepemilikannya adalah rantai yang sama yang dipakai
 * `FinanceNotificationService` untuk mengirim surat ke arah sebaliknya
 * (`ppdb_parent_accounts` → `guardian_id` → `student_guardians`) — dibaca lewat
 * `PpdbParentAccount::childStudentIds()` supaya "anak saya" berarti sama di
 * semua portal modul. Itu juga jawabannya §9: data anak tidak boleh tercampur.
 *
 * ## Angka dibaca dari dokumen, bukan dihitung ulang
 *
 * Portal tidak punya logika uang sendiri. `total`/`paid_amount`/`remaining_amount`
 * sebuah invoice adalah angka yang dipelihara service pembayaran dan
 * recalculator outstanding; credit balance pun turunan (penerimaan sah dikurangi
 * penerapannya) dan dibacanya lewat `FinanceReceivableService::creditBalances()`
 * — layar §12 memakai fungsi yang sama, jadi deposit seorang anak tidak mungkin
 * terbaca beda di dua tempat. Yang dilakukan kelas ini hanya mengelompokkan
 * angka-angka itu per jendela waktu (§10) — dan itu dilakukan di PHP atas baris
 * milik SATU anak, bukan lewat `SUM(CASE WHEN ...)` di SQL: kolom `date`
 * menyimpan `'Y-m-d'` di MySQL tapi `'Y-m-d 00:00:00'` lewat cast Laravel, dan
 * perbandingan tanggal di dua bentuk itu adalah jenis bug yang tidak kelihatan.
 * Jumlah invoice per santri kecil, jadi memuat barisnya lebih murah daripada
 * salah angka.
 */
class FinancePortalService
{
    /** Status invoice yang dihitung sebagai tagihan berjalan. */
    public const OUTSTANDING_STATUSES = [
        FinanceInvoice::STATUS_ISSUED,
        FinanceInvoice::STATUS_PARTIAL,
        FinanceInvoice::STATUS_OVERDUE,
    ];

    public function __construct(private readonly FinanceReceivableService $receivables) {}

    /**
     * Daftar anak + ringkasan keuangannya: layar "pilih anak" (§9).
     *
     * @return list<array<string, mixed>>
     */
    public function children(User $user): array
    {
        $ids = $this->childStudentIds($user);

        if ($ids === []) {
            return [];
        }

        $students = Student::query()
            ->whereIn('id', $ids)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'nis']);

        $invoices = FinanceInvoice::query()
            ->whereIn('student_id', $ids)
            ->whereNotIn('status', [FinanceInvoice::STATUS_DRAFT, FinanceInvoice::STATUS_CANCELLED])
            ->get();

        $credits = $this->receivables->creditBalances($ids);

        $rows = [];

        foreach ($students as $student) {
            $totals = $this->totals($invoices->where('student_id', (int) $student->id));
            $totals['credit_balance'] = $credits[(int) $student->id] ?? 0.0;

            $rows[] = [
                'student_id' => (int) $student->id,
                'full_name' => $student->full_name,
                'nis' => $student->nis,
                ...$totals,
            ];
        }

        return $rows;
    }

    /**
     * Ringkasan finansial satu santri (§9 header + §10): total, piutang, deposit,
     * dan angka yang sama dipecah per jendela waktu.
     *
     * @return array<string, mixed>
     */
    public function summary(int $studentId, ?Carbon $asOf = null): array
    {
        $now = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $year = $this->activeAcademicYear($now);

        $invoices = FinanceInvoice::query()
            ->where('student_id', $studentId)
            ->whereNotIn('status', [FinanceInvoice::STATUS_DRAFT, FinanceInvoice::STATUS_CANCELLED])
            ->get();

        $lifetime = $this->totals($invoices);
        $lifetime['credit_balance'] = $this->receivables->creditBalance($studentId);

        $student = Student::query()->whereKey($studentId)->first(['id', 'full_name', 'nis']);

        return [
            'student_id' => $studentId,
            'full_name' => $student?->full_name,
            'nis' => $student?->nis,
            'as_of' => $now->toDateString(),
            'academic_year' => $year === null ? null : [
                'id' => (int) $year->id,
                'name' => $year->name,
                'start_date' => $year->start_date?->toDateString(),
                'end_date' => $year->end_date?->toDateString(),
            ],
            'totals' => $lifetime,
            'periods' => [
                'current_month' => [
                    'label' => $now->translatedFormat('F Y'),
                    'from' => $now->copy()->startOfMonth()->toDateString(),
                    'to' => $now->copy()->endOfMonth()->toDateString(),
                    ...$this->totals($this->within($invoices, 'issue_date', $now->copy()->startOfMonth(), $now->copy()->endOfMonth())),
                ],
                'current_academic_year' => [
                    'label' => $year?->name,
                    'from' => $year?->start_date?->toDateString(),
                    'to' => $year?->end_date?->toDateString(),
                    ...$year === null
                        ? $this->totals(new Collection)
                        : $this->totals($this->within($invoices, 'issue_date', $year->start_date?->copy()->startOfDay(), $year->end_date?->copy()->endOfDay())),
                ],
                'lifetime' => [
                    'label' => 'Sepanjang waktu',
                    'from' => null,
                    'to' => null,
                    ...$this->totals($invoices),
                ],
            ],
        ];
    }

    /** Id santri milik pengguna; portal tidak pernah menerima id di luar daftar ini. */
    public function childStudentIds(User $user): array
    {
        return PpdbParentAccount::childStudentIds($user);
    }

    /**
     * Gerbang kepemilikan: bukan anak pengguna → 404 (bukan 403 — portal tidak
     * boleh mengonfirmasi bahwa id santri itu ada dan milik orang lain).
     */
    public function assertChild(User $user, mixed $studentId): int
    {
        $id = is_numeric($studentId) ? (int) $studentId : 0;

        if ($id <= 0 || ! in_array($id, $this->childStudentIds($user), true)) {
            throw new NotFoundHttpException('Data anak tidak ditemukan pada akun Anda.');
        }

        return $id;
    }

    // ==================== Internal ====================

    /**
     * Total sekumpulan invoice satu anak. Baris dibaca apa adanya dari kolom
     * yang sudah dipelihara service — tidak ada penjumlahan ulang alokasi di
     * sini selain credit balance yang memang tidak punya kolomnya.
     *
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return array<string, int|float>
     */
    private function totals(Collection $invoices): array
    {
        $outstanding = 0.0;
        $overdue = 0.0;
        $openCount = 0;
        $overdueCount = 0;

        foreach ($invoices as $invoice) {
            $remaining = round((float) $invoice->remaining_amount, 2);

            if (! in_array($invoice->status, self::OUTSTANDING_STATUSES, true) || $remaining <= 0.0001) {
                continue;
            }

            $outstanding = round($outstanding + $remaining, 2);
            $openCount++;

            if ($invoice->isOverdue()) {
                $overdue = round($overdue + $remaining, 2);
                $overdueCount++;
            }
        }

        return [
            'billing_total' => round((float) $invoices->sum(fn (FinanceInvoice $i): float => (float) $i->total), 2),
            'paid_total' => round((float) $invoices->sum(fn (FinanceInvoice $i): float => (float) $i->paid_amount), 2),
            'written_off_total' => round((float) $invoices->sum(fn (FinanceInvoice $i): float => (float) $i->written_off_amount), 2),
            'outstanding_total' => $outstanding,
            'overdue_total' => $overdue,
            'invoice_count' => $invoices->count(),
            'open_invoice_count' => $openCount,
            'overdue_invoice_count' => $overdueCount,
        ];
    }

    /** Baris yang tanggalnya jatuh dalam rentang; perbandingan di PHP, aman untuk `date` maupun `datetime`. */
    private function within(Collection $invoices, string $column, ?Carbon $from, ?Carbon $to): Collection
    {
        if ($from === null && $to === null) {
            return $invoices;
        }

        return $invoices->filter(function (FinanceInvoice $invoice) use ($column, $from, $to): bool {
            $date = $invoice->{$column};

            if (! $date instanceof Carbon) {
                return false;
            }

            return ($from === null || $date->gte($from)) && ($to === null || $date->lte($to));
        })->values();
    }

    private function activeAcademicYear(Carbon $asOf): ?AcademicYear
    {
        $year = AcademicYear::query()->where('is_active', true)->first();

        if ($year !== null) {
            return $year;
        }

        return AcademicYear::query()
            ->whereDate('start_date', '<=', $asOf)
            ->whereDate('end_date', '>=', $asOf)
            ->first();
    }
}
