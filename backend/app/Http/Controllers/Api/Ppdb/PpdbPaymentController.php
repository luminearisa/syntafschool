<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbPaymentRejectRequest;
use App\Http\Requests\Ppdb\PpdbPaymentStoreRequest;
use App\Http\Requests\Ppdb\PpdbPaymentVerifyRequest;
use App\Http\Resources\PpdbInvoiceResource;
use App\Http\Resources\PpdbPaymentResource;
use App\Models\PpdbInvoice;
use App\Models\PpdbPayment;
use App\Models\User;
use App\Services\Ppdb\PpdbPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran tagihan PPDB.
 *
 * Portal mengajukan (manual dengan bukti, atau Midtrans), petugas memverifikasi
 * atau menolak. Pembayaran sebagian dibiarkan: tagihan baru berstatus `paid`
 * setelah total pembayaran sah ≥ total tagihan (dihitung
 * `PpdbInvoiceService::recalculateTotals()`).
 */
class PpdbPaymentController extends BaseApiController
{
    public function __construct(protected PpdbPaymentService $payments)
    {
    }

    /**
     * GET /ppdb/payments — filter status, metode, tagihan, periode, rentang tanggal.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        $payments = $request->boolean('all')
            ? $this->payments->query($request)->get()
            : $this->payments->query($request)->paginate($perPage)->appends($request->query());

        return $this->successResponse(
            PpdbPaymentResource::collection($payments),
            'Daftar pembayaran PPDB berhasil diambil',
        );
    }

    /**
     * POST /ppdb/portal/invoices/{invoice}/pay.
     *
     * manual   → pembayaran `pending` + tagihan `pending`; panitia penerima
     *            permission `ppdb.payment.verify` dikabari.
     * midtrans → transaksi Snap; yang dikembalikan `redirect_url`/`snap_token`.
     *            Bila penyedia belum dikonfigurasi permintaan jadi 422 dengan
     *            pesan yang menjelaskan, bukan 500.
     */
    public function store(PpdbPaymentStoreRequest $request, PpdbInvoice $invoice): JsonResponse
    {
        $user = $this->actor($request);

        $this->payments->ownedInvoice($invoice, $user);

        $data = $request->validated();

        $proof = $this->payments->storeProof($request, $invoice);

        if ($proof !== null) {
            $data['proof_path'] = $proof;
        }

        $result = $this->payments->submit($invoice->load('registration'), $data, $user);

        $payment = $result['payment']->load('paymentMethod:id,name,code');

        return $this->createdResponse([
            'payment' => new PpdbPaymentResource($payment),
            'invoice' => new PpdbInvoiceResource($result['invoice']->load(['items', 'payments'])),
            'gateway' => $result['gateway'],
            'next_action' => $result['gateway'] === null ? 'wait_verification' : 'redirect_to_gateway',
        ], $result['gateway'] === null
            ? 'Pembayaran diterima dan menunggu verifikasi panitia'
            : 'Transaksi pembayaran online berhasil dibuat');
    }

    /**
     * POST /ppdb/payments/{payment}/verify — bukti manual disahkan.
     */
    public function verify(PpdbPaymentVerifyRequest $request, PpdbPayment $payment): JsonResponse
    {
        $payment = $this->payments->verify(
            $payment,
            $this->actor($request),
            $request->validated('note'),
            $request->date('paid_at'),
        );

        return $this->successResponse(
            new PpdbPaymentResource($payment->load(['paymentMethod:id,name,code', 'invoice.items', 'invoice.registration.applicant'])),
            'Pembayaran berhasil diverifikasi dan dicatat pada tagihan',
        );
    }

    /**
     * POST /ppdb/payments/{payment}/reject — alasan wajib, tagihan dihitung ulang.
     */
    public function reject(PpdbPaymentRejectRequest $request, PpdbPayment $payment): JsonResponse
    {
        $payment = $this->payments->reject($payment, $this->actor($request), (string) $request->validated('reason'));

        return $this->successResponse(
            new PpdbPaymentResource($payment->load(['paymentMethod:id,name,code', 'invoice.items', 'invoice.registration.applicant'])),
            'Pembayaran ditolak dan orang tua sudah dikabari',
        );
    }

    /**
     * Pemanggil harus pengguna terdaftar; pembayaran portal juga harus miliknya.
     */
    private function actor(Request $request): User
    {
        $user = $request->user();

        if ($user === null) {
            throw ValidationException::withMessages(['auth' => 'Autentikasi diperlukan untuk memproses pembayaran.']);
        }

        return $user;
    }
}
