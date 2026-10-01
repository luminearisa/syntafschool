<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Student;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Services\Mobile\ContextService;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletSettingsService;
use App\Services\Wallet\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Dompet (wallet) santri untuk wali murid & siswa.
 *
 * Portal wallet belum ada (endpoint wallet hanya untuk petugas). Di sini
 * hanya dua hal yang diekspos: BACA dompet milik anak/diri sendiri dan
 * MENGAJUKAN top up lewat payment gateway. Saldo tidak pernah ditulis dari
 * sini — pengisian saldo tetap lewat WalletTopUpService (webhook/verifikasi).
 * Top up transfer manual (butuh unggah bukti) belum tersedia di aplikasi.
 */
class MobileWalletController extends BaseApiController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly WalletService $wallets,
        private readonly WalletTopUpService $topups,
        private readonly WalletSettingsService $settings,
    ) {}

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->assertOwn($request, $student->id);

        $wallet = $this->walletOf($student->id);

        if ($wallet === null) {
            return $this->successResponse(['has_wallet' => false], 'Anak ini belum memiliki dompet.');
        }

        $ledger = $this->wallets->ledger($wallet, tap(clone $request, fn ($r) => $r->query->set('per_page', '20')), staff: false);

        return $this->successResponse([
            'has_wallet' => true,
            'wallet' => [
                'number' => $wallet->number,
                'status' => $wallet->status,
                'balance' => round((float) $wallet->balance, 2),
                'held_balance' => round((float) $wallet->held_balance, 2),
                'can_transact' => $wallet->canTransact(),
            ],
            'topup' => [
                'gateway_enabled' => (bool) $this->settings->get('gateway_enabled', false),
                'min_amount' => (int) $this->settings->get('topup_min_amount', 0),
                'max_amount' => (int) $this->settings->get('topup_max_amount', 0),
                'allowed' => $this->contexts_isGuardian($request),
            ],
            'ledger' => $ledger->items(),
            'topups' => $wallet->topups()->limit(10)->get()->map(fn (WalletTopUp $t) => [
                'number' => $t->number,
                'amount' => round((float) $t->amount, 2),
                'method' => $t->method,
                'status' => $t->status,
                'created_at' => $t->created_at?->toIso8601String(),
                'expires_at' => $t->payment_expired_at?->toIso8601String(),
                // Lanjutkan pembayaran hanya selama kanal masih hidup.
                'redirect_url' => $t->status === WalletTopUp::STATUS_PENDING_PAYMENT && $t->payment_expired_at?->isFuture()
                    ? ($t->payment_payload['redirect_url'] ?? null)
                    : null,
            ])->all(),
        ], 'Dompet berhasil diambil');
    }

    public function topup(Request $request, Student $student): JsonResponse
    {
        abort_unless($this->contexts_isGuardian($request), 403, 'Top up hanya dapat dilakukan oleh wali murid.');
        $this->assertOwn($request, $student->id);

        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        $wallet = $this->walletOf($student->id);
        abort_if($wallet === null, 404, 'Anak ini belum memiliki dompet.');

        // Satu top up gateway yang masih menunggu pembayaran per dompet per akun:
        // tanpa ini setiap ketukan menumpuk permintaan yang menggantung.
        $waiting = $wallet->topups()
            ->where('requested_by', $request->user()->id)
            ->where('status', WalletTopUp::STATUS_PENDING_PAYMENT)
            ->where('payment_expired_at', '>', now())
            ->first();

        if ($waiting !== null) {
            throw ValidationException::withMessages(['amount' => "Masih ada top up yang menunggu pembayaran ({$waiting->number}). Selesaikan atau tunggu hingga kedaluwarsa."]);
        }

        $result = $this->topups->requestGateway($wallet, ['amount' => $data['amount']], $request->user());

        return $this->createdResponse([
            'number' => $result['topup']->number,
            'amount' => $result['gateway']['gross_amount'],
            'redirect_url' => $result['gateway']['redirect_url'],
            'expires_at' => $result['gateway']['expires_at'],
        ], 'Lanjutkan pembayaran di halaman penyedia pembayaran.');
    }

    private function walletOf(int $studentId): ?Wallet
    {
        return Wallet::query()
            ->where('student_id', $studentId)
            ->whereNotIn('status', [Wallet::STATUS_CLOSED])
            ->latest('id')
            ->first();
    }

    private function contexts_isGuardian(Request $request): bool
    {
        return $request->attributes->get('mobile_context') === ContextService::GUARDIAN;
    }

    /** Dompet hanya milik anak akun ini (wali) atau diri sendiri (siswa); selain itu 404. */
    private function assertOwn(Request $request, int $studentId): void
    {
        $allowed = $this->contexts_isGuardian($request)
            ? $this->contexts->childStudentIds($request->user())
            : array_filter([$this->contexts->studentId($request->user())]);

        abort_unless(in_array($studentId, $allowed, true), 404, 'Data anak tidak ditemukan pada akun Anda.');
    }
}
