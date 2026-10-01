<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FinanceInvoiceResource;
use App\Http\Resources\FinancePaymentPlanResource;
use App\Http\Resources\FinancePaymentResource;
use App\Http\Resources\FinanceReceiptResource;
use App\Http\Resources\FinanceReRegistrationResource;
use App\Models\FinanceBankAccount;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceReceipt;
use App\Models\FinanceReRegistration;
use App\Models\Student;
use App\Services\Finance\FinancePaymentPlanService;
use App\Services\Finance\FinancePortalService;
use App\Services\Finance\FinanceReceivableService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Portal orang tua Keuangan (§9) — HANYA BACA.
 *
 * Tidak ada satu pun endpoint tulis di sini: orang tua melihat tagihannya, dan
 * membayar lewat kasir/portal pembayaran yang memang punya jalur uangnya (§19).
 *
 * Otorisasinya bukan permission finance (orang tua tidak memegang permission
 * admin, §24) melainkan kepemilikan anak: `assertChild()` dijalankan lebih dulu
 * di SETIAP endpoint yang menyebut santri, dan hanya id anak milik login yang
 * lolos — itu jaminan §9 "data anak tidak boleh tercampur".
 *
 * Penerimaan yang sama dipakai layar admin: resource, filter, dan bentuk
 * paginasinya identik, jadi portal tidak melahirkan bentuk data kedua.
 */
class FinancePortalController extends BaseApiController
{
    public function __construct(
        private readonly FinancePortalService $portal,
        private readonly FinanceReceivableService $receivables,
        private readonly FinancePaymentPlanService $plans,
    ) {}

    /** Layar "pilih anak" (§9): anak + angka ringkasnya, tanpa detail. */
    public function children(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->portal->children($request->user()),
            'Daftar anak berhasil diambil',
        );
    }

    /** Ringkasan finansial satu anak (§9 header + §10). */
    public function summary(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        return $this->successResponse($this->portal->summary($childId), 'Ringkasan keuangan anak berhasil diambil');
    }

    /**
     * Tagihan anak (§9): invoice. `category` memecah layar jadi SPP / Tagihan
     * Lain lewat kode jenis biaya, bukan lewat pengelompokan yang dikarang
     * portal — sekolah bebas menamai jenisnya.
     */
    public function invoices(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $codes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->input('fee_type', '')),
        )));

        $month = $this->month($request);

        $query = FinanceInvoice::query()
            ->with(['student:id,full_name,nis', 'billing', 'costCenter', 'items.feeType'])
            ->withCount('items')
            ->where('student_id', $childId)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
            ->when($codes !== [], fn (Builder $q) => $q
                ->whereHas('items.feeType', fn (Builder $f) => $f->whereIn('code', $codes)))
            // Daftar ulang punya layarnya sendiri (§9), jadi invoice-nya tidak
            // muncul dua kali di daftar tagihan.
            ->when(! $request->boolean('include_re_registration'), fn (Builder $q) => $q
                ->whereNotIn('id', FinanceReRegistration::query()->whereNotNull('invoice_id')->select('invoice_id')))
            ->when($month !== null, fn (Builder $q) => $q->whereBetween(
                'issue_date',
                [$month->copy()->startOfDay(), $month->copy()->endOfMonth()->endOfDay()],
            ))
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        return $this->paged(
            $query,
            $request,
            FinanceInvoiceResource::class,
            ['statuses' => FinanceInvoice::STATUSES, 'status_labels' => FinanceInvoice::STATUS_LABELS],
            'Daftar tagihan anak berhasil diambil',
        );
    }

    /** Daftar ulang anak (§9). */
    public function reRegistrations(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $query = FinanceReRegistration::query()
            ->with(['student:id,full_name,nis', 'currentAcademicYear', 'nextAcademicYear', 'invoice', 'feePackage'])
            ->where('student_id', $childId)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->paged(
            $query,
            $request,
            FinanceReRegistrationResource::class,
            ['statuses' => FinanceReRegistration::STATUSES, 'status_labels' => FinanceReRegistration::STATUS_LABELS],
            'Daftar ulang anak berhasil diambil',
        );
    }

    /** Pembayaran + riwayat pembayaran anak (§9). */
    public function payments(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $query = FinancePayment::query()
            ->with(['student:id,full_name,nis', 'invoice', 'receipt'])
            ->where('student_id', $childId)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('invoice_id'), fn (Builder $q) => $q->where('invoice_id', (int) $request->input('invoice_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderByDesc('id');

        return $this->paged(
            $query,
            $request,
            FinancePaymentResource::class,
            ['statuses' => FinancePayment::STATUSES, 'status_labels' => FinancePayment::STATUS_LABELS],
            'Daftar pembayaran anak berhasil diambil',
        );
    }

    /** Kuitansi / bukti pembayaran anak (§9, §25). */
    public function receipts(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $query = FinanceReceipt::query()
            ->with(['payment', 'student:id,full_name,nis', 'invoice'])
            ->where('student_id', $childId)
            ->when($request->has('cancelled'), fn (Builder $q) => $q->where('is_cancelled', $request->boolean('cancelled')))
            ->orderByDesc('id');

        return $this->paged($query, $request, FinanceReceiptResource::class, [], 'Daftar kuitansi anak berhasil diambil');
    }

    /** Piutang anak (§9): invoice outstanding + ember umur, angka satu sumber dengan layar AR. */
    public function receivables(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $query = $this->receivables->query(['student_id' => $childId])
            ->orderBy('due_date')
            ->orderBy('id');

        return $this->paged(
            $query,
            $request,
            FinanceInvoiceResource::class,
            ['summary' => $this->receivables->summary(['student_id' => $childId])],
            'Daftar piutang anak berhasil diambil',
        );
    }

    /**
     * Bayar tagihan anak lewat payment gateway (aplikasi orang tua).
     *
     * Aturan yang ditegakkan di sini — selebihnya (alokasi, jurnal, kuitansi)
     * tetap dilakukan FinancePaymentService/FinanceGatewayService yang sama dengan
     * jalur kasir:
     *  1. Kepemilikan: invoice harus milik anak akun ini (404 bila bukan).
     *  2. Fitur harus diaktifkan sekolah (`portal_payment_enabled`) dan punya
     *     rekening tujuan (`portal_payment_bank_account_id`) — bawaannya mati.
     *  3. Hanya invoice berjalan dengan sisa > 0; nominal ≤ sisa (default: sisa).
     *  4. Satu pembayaran online yang masih menunggu per invoice per orang tua:
     *     tanpa ini setiap ketukan menumpuk `pending` yang menggantung.
     *  5. `Idempotency-Key` dari klien → ulang jaringan tidak membuat dua pembayaran.
     *  6. Bila kanal gateway gagal dibuka, pembayaran yang baru lahir ditolak
     *     (bukan dibiarkan `pending` menunggu verifikasi petugas yang tak berarti).
     */
    public function pay(Request $request, Student $student, FinanceInvoice $invoice): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        abort_unless((int) $invoice->student_id === $childId, 404, 'Tagihan tidak ditemukan pada akun Anda.');

        $settings = app(\App\Services\Finance\FinanceSettingsService::class);
        $bankId = (int) $settings->get('portal_payment_bank_account_id', 0);

        if (! (bool) $settings->get('portal_payment_enabled', false) || $bankId <= 0
            || ! FinanceBankAccount::query()->whereKey($bankId)->exists()) {
            throw ValidationException::withMessages(['payment' => 'Pembayaran online belum diaktifkan oleh sekolah. Silakan bayar melalui kasir atau transfer ke rekening sekolah.']);
        }

        if (! in_array($invoice->status, \App\Services\Finance\FinancePortalService::OUTSTANDING_STATUSES, true)
            || (float) $invoice->remaining_amount <= 0.0001) {
            throw ValidationException::withMessages(['invoice' => 'Tagihan ini tidak memiliki sisa yang harus dibayar.']);
        }

        $remaining = round((float) $invoice->remaining_amount, 2);
        $requested = $request->validate(['amount' => ['nullable', 'numeric', 'min:1']])['amount'] ?? null;
        $amount = round($requested === null ? $remaining : (float) $requested, 2);

        if ($amount > $remaining + 0.0001) {
            throw ValidationException::withMessages(['amount' => 'Nominal melebihi sisa tagihan (Rp '.number_format($remaining, 0, ',', '.').').']);
        }

        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');
        $key = is_string($key) && $key !== '' ? 'portal-'.$key : null;

        $waiting = FinancePayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('created_by', $request->user()->id)
            ->where('status', FinancePayment::STATUS_PENDING)
            ->where('method', FinancePayment::METHOD_GATEWAY)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($key !== null, fn (Builder $q) => $q->where(fn (Builder $k) => $k->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $key)))
            ->latest('id')
            ->first();

        if ($waiting !== null) {
            throw ValidationException::withMessages(['payment' => "Masih ada pembayaran online yang menunggu untuk tagihan ini ({$waiting->payment_number}). Selesaikan atau tunggu hingga kedaluwarsa."]);
        }

        $payments = app(\App\Services\Finance\FinancePaymentService::class);

        $payment = $payments->record([
            'invoice_id' => $invoice->id,
            'student_id' => $childId,
            'customer_type' => $invoice->customer_type,
            'customer_id' => $invoice->customer_id,
            'amount' => $amount,
            'method' => FinancePayment::METHOD_GATEWAY,
            'gateway' => \App\Services\Payments\PaymentGatewayManager::DEFAULT_PROVIDER,
            'bank_account_id' => $bankId,
            'idempotency_key' => $key,
            'notes' => 'Dibayar lewat aplikasi orang tua',
        ], $request->user());

        // Permintaan ulang dengan kunci yang sama mengembalikan pembayaran lama.
        if ($payment->status !== FinancePayment::STATUS_PENDING) {
            return $this->successResponse(new FinancePaymentResource($payment), 'Pembayaran sudah diproses');
        }

        try {
            $charge = app(\App\Services\Finance\FinanceGatewayService::class)->charge($payment);
        } catch (\Throwable $e) {
            report($e);
            $payments->reject($payment, 'Kanal pembayaran online gagal dibuka.', $request->user(), true);

            throw ValidationException::withMessages(['payment' => 'Kanal pembayaran online sedang tidak tersedia. Coba lagi nanti atau bayar melalui kasir.']);
        }

        return $this->createdResponse([
            'payment_number' => $payment->payment_number,
            'amount' => $amount,
            'redirect_url' => $charge['redirect_url'],
            'token' => $charge['token'],
            'expires_at' => $charge['expires_at'],
        ], 'Lanjutkan pembayaran di halaman penyedia pembayaran.');
    }

    /** Rencana pembayaran anak (§9). */
    public function paymentPlans(Request $request, Student $student): JsonResponse
    {
        $childId = $this->portal->assertChild($request->user(), $student->id);

        $plans = $this->plans->plans(['student_id' => $childId]);

        return $this->successResponse(
            FinancePaymentPlanResource::collection($plans)->additional([
                'meta' => ['config' => $this->plans->config()],
            ]),
            'Rencana pembayaran anak berhasil diambil',
        );
    }

    /**
     * `?month=2026-09` sebagai awal bulan. Format yang salah dibaca "tanpa
     * filter" alih-alih meledak: layar portal tidak seharusnya gagal hanya karena
     * satu ketikan tanggal.
     */
    private function month(Request $request): ?Carbon
    {
        $value = trim((string) $request->input('month', ''));

        if (! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
    }

    /**
     * Bentuk daftar semua endpoint portal: paginasi + `appends` + meta, sama
     * dengan layar admin — portal tidak melahirkan bentuk daftar kedua.
     *
     * @param  class-string  $resource
     * @param  array<string, mixed>  $meta
     */
    private function paged(Builder $query, Request $request, string $resource, array $meta, string $message): JsonResponse
    {
        if ($request->boolean('all')) {
            return $this->successResponse($resource::collection($query->get()), $message);
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            $resource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => $meta]),
            $message,
        );
    }
}
