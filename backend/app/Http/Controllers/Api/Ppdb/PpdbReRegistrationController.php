<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbReRegistrationRequest;
use App\Http\Resources\PpdbInvoiceResource;
use App\Http\Resources\PpdbRegistrationResource;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Services\Ppdb\PpdbInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar ulang peserta yang lulus.
 *
 * Dua aksi, dan urutannya sengaja dipaksa oleh service:
 * - `open`     : `passed` → `re_registration`, tagihan jenis `re_registration`
 *   dijamin ada (jatuh tempo dari `ppdb_periods.re_registration_end`), orang tua
 *   dikabari beserta nominal & tenggatnya.
 * - `complete` : `re_registration` → `completed`, **hanya bila** tagihan daftar
 *   ulangnya sudah lunas — sisa pembayaran dilaporkan lewat pesan 422.
 *
 * `waitlisted` tidak punya jalur daftar ulang: harus ditetapkan lulus lebih dulu
 * lewat `POST /ppdb/registrations/{id}/result` (kuota dijaga). Konversi menjadi
 * siswa baru mungkin setelah status `completed`.
 */
class PpdbReRegistrationController extends BaseApiController
{
    public function __construct(protected PpdbInvoiceService $invoices)
    {
    }

    /**
     * POST /ppdb/registrations/{registration}/re-registration/open.
     *
     * Idempoten: memanggil ulang pada pendaftaran yang sedang daftar ulang
     * mengembalikan tagihan yang sudah ada tanpa membuat tagihan kedua.
     */
    public function open(PpdbReRegistrationRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $result = $this->invoices->openReRegistration(
            $registration,
            $this->actor($request),
            $request->validated('note'),
        );

        return $this->successResponse(
            $this->payload($result['registration'], $result['invoice']) + [
                'opened' => $result['opened'],
                'deadline' => $result['deadline'],
            ],
            $result['opened']
                ? 'Daftar ulang dibuka dan orang tua sudah dikabari'
                : 'Daftar ulang sudah terbuka pada pendaftaran ini; tagihan yang ada dipakai kembali',
        );
    }

    /**
     * POST /ppdb/registrations/{registration}/re-registration/complete.
     *
     * Menolak (422) selama masih ada tagihan daftar ulang yang belum lunas.
     */
    public function complete(PpdbReRegistrationRequest $request, PpdbRegistration $registration): JsonResponse
    {
        $result = $this->invoices->completeReRegistration(
            $registration,
            $this->actor($request),
            $request->validated('note'),
        );

        return $this->successResponse(
            $this->payload($result['registration'], $result['invoice']) + [
                'can_convert' => true,
            ],
            'Daftar ulang selesai; calon sudah dapat dijadikan siswa',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PpdbRegistration $registration, mixed $invoice): array
    {
        $registration->loadMissing([
            'applicant:id,full_name,nickname',
            'program:id,name,code',
            'wave:id,name,code',
            'period:id,name,code,re_registration_start,re_registration_end',
        ]);

        return [
            'registration' => PpdbRegistrationResource::row($registration),
            'invoice' => $invoice === null ? null : new PpdbInvoiceResource(
                $invoice->loadMissing(['items', 'payments.paymentMethod'])
            ),
            'window' => [
                'start' => $registration->period?->re_registration_start?->toDateString(),
                'end' => $registration->period?->re_registration_end?->toDateString(),
            ],
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Autentikasi diperlukan untuk mengakses data ini.');

        return $user;
    }
}
