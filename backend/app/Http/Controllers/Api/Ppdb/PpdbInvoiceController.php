<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbInvoiceCancelRequest;
use App\Http\Requests\Ppdb\PpdbInvoiceRefundRequest;
use App\Http\Requests\Ppdb\PpdbInvoiceStoreRequest;
use App\Http\Resources\PpdbInvoiceResource;
use App\Models\PpdbInvoice;
use App\Models\PpdbRegistration;
use App\Services\Ppdb\PpdbInvoiceService;
use App\Services\Ppdb\PpdbPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tagihan PPDB.
 *
 * Sisi admin: daftar/tagihan tunggal/buat tagihan/pengingat/batalkan/refund.
 * Sisi portal: `forRegistration` yang otomatis menyiapkan tagihan sehingga orang
 * tua tidak pernah melihat halaman kosong sebelum panitia menekan tombol apa pun.
 *
 * Aksi yang mengakhiri siklus tagihan (`cancel`, `refund`) diberi hak yang sama
 * dengan verifikasi pembayaran karena sama-sama keputusan bendahara, dan
 * penjaga statusnya ada di `PpdbInvoiceService` — bukan di sini — supaya jalur
 * terjadwal (`ppdb:expire-invoices`) melewati aturan yang sama.
 *
 * Pembeda bentuk respons ada di `PpdbInvoiceResource`: data internal (catatan
 * petugas, `fee_id`, jejak audit) hanya keluar untuk pemegang permission, jadi
 * endpoint portal tidak pernah membocorkan struktur biaya internal.
 */
class PpdbInvoiceController extends BaseApiController
{
    public function __construct(
        protected PpdbInvoiceService $invoices,
        protected PpdbPaymentService $payments,
    ) {
    }

    /**
     * GET /ppdb/invoices — filter status/jenis/periode/program/gelombang/rentang
     * tanggal + pencarian nomor tagihan, nomor pendaftaran, dan nama calon.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        $invoices = $request->boolean('all')
            ? $this->invoices->query($request)->get()
            : $this->invoices->query($request)->paginate($perPage)->appends($request->query());

        return $this->successResponse(
            PpdbInvoiceResource::collection($invoices),
            'Daftar tagihan PPDB berhasil diambil',
        );
    }

    /**
     * POST /ppdb/invoices — idempoten: tagihan jenis ini yang masih berlaku
     * dipakai ulang, bukan dibuat baru (termasuk yang sudah lunas).
     */
    public function store(PpdbInvoiceStoreRequest $request): JsonResponse
    {
        $type = (string) ($request->validated('type') ?? PpdbInvoice::TYPE_REGISTRATION);

        $registration = PpdbRegistration::query()->findOrFail((int) $request->validated('registration_id'));

        $existing = $this->invoices->liveInvoiceFor($registration, $type);

        $invoice = $this->invoices->ensureForRegistration($registration, $type);

        $payload = new PpdbInvoiceResource($invoice->load(['items', 'payments.paymentMethod', 'registration.applicant']));

        return $existing === null
            ? $this->createdResponse($payload, 'Tagihan berhasil dibuat dari konfigurasi biaya yang berlaku')
            : $this->successResponse($payload, 'Tagihan aktif untuk jenis ini sudah tersedia dan dipakai kembali');
    }

    /**
     * GET /ppdb/invoices/{invoice} — rincian + riwayat pembayaran + identitas pendaftar.
     */
    public function show(PpdbInvoice $invoice): JsonResponse
    {
        $invoice->load([
            'items',
            'payments.paymentMethod',
            'payments.verifiedBy:id,name',
            'registration.applicant:id,full_name,nickname',
            'registration.period:id,name,code',
            'registration.program:id,name,code',
            'registration.wave:id,name,code',
        ]);

        return $this->successResponse(
            new PpdbInvoiceResource($invoice),
            'Detail tagihan PPDB berhasil diambil',
        );
    }

    /**
     * POST /ppdb/invoices/{invoice}/remind.
     *
     * Kunci dedupe: `users.id` orang tua + type `payment_reminder` +
     * `data->registration_number` (dipakai `PpdbNotificationService::alreadySent()`),
     * jadi satu orang tua hanya menerima satu pengingat per pendaftaran.
     */
    public function remind(Request $request, PpdbInvoice $invoice): JsonResponse
    {
        $result = $this->invoices->remind($invoice, $request->user());

        $result['invoice']->loadMissing(['items', 'payments.paymentMethod', 'registration.applicant']);

        return $this->successResponse(
            [
                'sent' => $result['sent'],
                'reason' => $result['reason'],
                'invoice' => new PpdbInvoiceResource($result['invoice']),
            ],
            $result['sent']
                ? 'Pengingat pembayaran terkirim ke orang tua'
                : 'Pengingat untuk pendaftaran ini sudah pernah dikirim, tidak dibuat duplikatnya',
        );
    }

    /**
     * POST /ppdb/invoices/{invoice}/cancel — batalkan tagihan.
     *
     * Alasan wajib dan penjaga status ada di layanan: tagihan yang sudah
     * menerima pembayaran sah tidak bisa dibatalkan (jalur keluarnya refund),
     * dan tagihan lunas tidak bisa dihapus piutangnya diam-diam. Dibalas 422
     * beserta alasan penolakannya, bukan 500.
     */
    public function cancel(PpdbInvoiceCancelRequest $request, PpdbInvoice $invoice): JsonResponse
    {
        $invoice = $this->invoices->cancel($invoice, $request->user(), (string) $request->validated('reason'));

        return $this->successResponse(
            new PpdbInvoiceResource($this->loaded($invoice)),
            'Tagihan berhasil dibatalkan dan orang tua sudah dikabari',
        );
    }

    /**
     * POST /ppdb/invoices/{invoice}/refund — catat pengembalian dana.
     *
     * Hanya untuk tagihan yang uangnya benar-benar sudah masuk; nominal yang
     * kembali tidak diminta dari luar agar tidak bisa berbeda dari pembayaran
     * yang diverifikasi. Hak yang sama dengan verifikasi pembayaran
     * (`ppdb.payment.verify`) karena ini juga keputusan bendahara.
     */
    public function refund(PpdbInvoiceRefundRequest $request, PpdbInvoice $invoice): JsonResponse
    {
        $invoice = $this->invoices->refund($invoice, $request->user(), (string) $request->validated('reason'));

        return $this->successResponse(
            new PpdbInvoiceResource($this->loaded($invoice)),
            'Pengembalian dana berhasil dicatat pada tagihan',
        );
    }

    /**
     * GET /ppdb/portal/registrations/{registration}/payment — tagihan milik sendiri.
     *
     * Tagihan disiapkan otomatis bila belum ada. Yang dikembalikan hanya nomor,
     * jenis, nominal yang harus dibayar, rincian, riwayat pembayaran, dan
     * instruksi dari master `payment_methods` aktif.
     */
    public function forRegistration(Request $request, PpdbRegistration $registration): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Autentikasi diperlukan untuk mengakses data ini.');

        $this->invoices->ownedRegistration($registration, $user);

        $this->invoices->ensureForRegistration($registration);

        $registration->loadMissing('applicant:id,full_name,nickname');

        $invoices = $this->invoices->forRegistration($registration);

        $outstanding = round((float) $invoices->sum(
            fn (PpdbInvoice $invoice) => in_array($invoice->status, [
                PpdbInvoice::STATUS_UNPAID, PpdbInvoice::STATUS_PENDING,
                PpdbInvoice::STATUS_FAILED, PpdbInvoice::STATUS_EXPIRED,
            ], true) ? $invoice->outstandingAmount() : 0,
        ), 2);

        return $this->successResponse([
            'registration' => [
                'id' => $registration->id,
                'registration_number' => $registration->registration_number,
                'status' => $registration->status,
                'payment_status' => $registration->payment_status,
                'applicant_name' => $registration->applicant?->full_name,
            ],
            'invoices' => PpdbInvoiceResource::collection($invoices),
            'outstanding_amount' => $outstanding,
            'currency' => 'IDR',
            'instructions' => $this->invoices->manualInstructions(),
            'online_available' => $this->payments->onlineAvailable(),
        ], 'Informasi pembayaran berhasil diambil');
    }

    /**
     * Relasi yang dibutuhkan `PpdbInvoiceResource` setelah sebuah aksi tagihan:
     * sama seperti yang dibaca `show()`, tanpa memaksa tiap aksi menyusun
     * daftarnya sendiri.
     */
    private function loaded(PpdbInvoice $invoice): PpdbInvoice
    {
        $invoice->load([
            'items',
            'payments.paymentMethod',
            'payments.verifiedBy:id,name',
            'registration.applicant:id,full_name,nickname',
            'registration.period:id,name,code',
            'registration.program:id,name,code',
        ]);

        return $invoice;
    }
}
