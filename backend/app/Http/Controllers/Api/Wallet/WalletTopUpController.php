<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Wallet\WalletTopUpRejectRequest;
use App\Http\Requests\Wallet\WalletTopUpStoreRequest;
use App\Http\Requests\Wallet\WalletTopUpVerifyRequest;
use App\Http\Resources\WalletTopUpResource;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Services\Wallet\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Top up wallet — pengajuan, antrean verifikasi, dan keputusan petugas.
 *
 * Otorisasi sepenuhnya ada pada middleware route (`permission:wallet.topup.*`);
 * controller tidak menambahkan pemeriksaan sendiri, dan tidak menyentuh Eloquent:
 * semua baca/tulis dilakukan `WalletTopUpService` yang memegang aturan saldo (§14, §39).
 *
 * Dua jalur pengajuan (§10):
 * - gateway → respons berisi `token`/`redirect_url`; saldo BELUM bertambah, tunggu
 *   webhook (§11);
 * - manual  → rekening tujuan setoran ikut dikembalikan agar pengguna bisa transfer,
 *   lalu permintaan masuk antrean verifikasi petugas (§12).
 */
class WalletTopUpController extends BaseApiController
{
    public function __construct(protected WalletTopUpService $topups)
    {
    }

    /**
     * GET /wallet/topups — daftar + filter.
     *
     * `?pending_verification=1` mempersempit ke antrean kerja petugas (§13),
     * urut dari yang paling lama menunggu.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        $builder = $request->boolean('pending_verification')
            ? $this->topups->awaitingVerificationQuery()
            : $this->topups->query($request);

        $topups = $request->boolean('all')
            ? $builder->get()
            : $builder->paginate($perPage)->appends($request->query());

        return $this->successResponse(
            WalletTopUpResource::collection($topups),
            'Daftar top up wallet berhasil diambil',
        );
    }

    /**
     * GET /wallet/topups/{topup}.
     */
    public function show(WalletTopUp $topup): JsonResponse
    {
        return $this->successResponse(
            new WalletTopUpResource($this->topups->withDetails($topup)),
            'Detail top up wallet berhasil diambil',
        );
    }

    /**
     * POST /wallet/wallets/{wallet}/topups.
     *
     * Nominal di luar rentang pengaturan, wallet yang tidak aktif, dan gateway yang
     * belum terpasang kredensialnya semuanya berujung 422 berkoncal — bukan 500.
     */
    public function store(WalletTopUpStoreRequest $request, Wallet $wallet): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $request->validated();

        // Berkas tidak ikut `validated()`: ia objek terunggah, bukan scalar.
        if ($request->hasFile('proof')) {
            $data['proof_file'] = $request->file('proof');
        }

        if ($data['method'] === WalletTopUp::METHOD_GATEWAY) {
            $result = $this->topups->requestGateway($wallet, $data, $actor);

            return $this->createdResponse([
                'topup' => new WalletTopUpResource($result['topup']),
                'gateway' => $result['gateway'],
                'destination_accounts' => [],
                'next_action' => 'pay_at_gateway',
            ], 'Permintaan top up dibuat. Saldo bertambah setelah pembayaran terkonfirmasi.');
        }

        $topup = $this->topups->requestManual($wallet, $data, $actor);

        return $this->createdResponse([
            'topup' => new WalletTopUpResource($topup),
            'gateway' => null,
            'destination_accounts' => $this->topups->destinationAccounts(),
            'next_action' => 'upload_proof_then_wait_verification',
        ], 'Pengajuan top up manual diterima dan menunggu verifikasi petugas.');
    }

    /**
     * POST /wallet/topups/{topup}/verify — pembayaran manual/gateway disahkan.
     *
     * Satu langkah ini yang menambahkan saldo: ledger di-post lalu permintaan
     * ditutup, dalam satu transaksi database (§39).
     */
    public function verify(WalletTopUpVerifyRequest $request, WalletTopUp $topup): JsonResponse
    {
        $verified = $this->topups->verify(
            $topup,
            $this->actor($request),
            $request->validated('note'),
            $request->date('paid_at'),
        );

        return $this->successResponse(
            new WalletTopUpResource($verified),
            'Top up disetujui dan saldo wallet sudah bertambah',
        );
    }

    /**
     * POST /wallet/topups/{topup}/reject — alasan wajib, saldo tidak berubah.
     */
    public function reject(WalletTopUpRejectRequest $request, WalletTopUp $topup): JsonResponse
    {
        $rejected = $this->topups->reject($topup, $this->actor($request), (string) $request->validated('reason'));

        return $this->successResponse(
            new WalletTopUpResource($rejected),
            'Top up ditolak dan saldo wallet tidak berubah',
        );
    }

    /** Pemanggil harus pengguna terdaftar (route ini selalu di dalam auth:sanctum). */
    private function actor(Request $request): User
    {
        $user = $request->user();

        if ($user === null) {
            throw ValidationException::withMessages([
                'auth' => 'Autentikasi diperlukan untuk mengajukan atau memverifikasi top up.',
            ]);
        }

        return $user;
    }
}
