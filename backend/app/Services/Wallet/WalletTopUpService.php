<?php

namespace App\Services\Wallet;

use App\Contracts\PaymentGateway;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletBankAccount;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Top up wallet: payment gateway (§11) dan transfer manual (§12), plus verifikasi
 * petugas (§13) dan webhook penyedia (§30).
 *
 * Tiga keadaan sengaja dipisah dan tidak pernah saling menggantikan (§14):
 * `status` (daur hidup permintaan), `payment_status` (daur hidup pembayaran), dan
 * `transaction_id` (baris ledger tempat saldo benar-benar bertambah). Kelas ini
 * adalah satu-satunya tempat aturan itu dirangkai; controller hanya meneruskan.
 *
 * Dua aturan yang menopang sisanya:
 *
 * 1. **Saldo hanya lewat `WalletLedgerService::post()`** — tidak ada satu pun
 *    penulisan `balance` di sini. Update status top up dan post ledger terjadi di
 *    SATU transaksi database dengan baris top up dikunci, sehingga keadaan
 *    "pembayaran PAID tetapi ledger tidak ada" tidak pernah bisa terlihat dari luar
 *    (§39).
 * 2. **Webhook idempoten** — notifikasi dinilai dengan `WalletTopUp::paymentRankOf()`,
 *    bukan dibandingkan sebagai string. Notifikasi ganda, notifikasi lama, dan
 *    `expire` yang datang setelah `settlement` otomatis kalah peringkat sehingga
 *    diabaikan; saldo tetap bertambah tepat satu kali (§30).
 *
 * Gateway selalu diakses lewat `PaymentGatewayManager` + kontrak
 * `App\Contracts\PaymentGateway`: kelas ini tidak pernah menyebut Midtrans, sehingga
 * provider kedua ditambahkan tanpa menyentuh alur wallet (§29).
 */
class WalletTopUpService
{
    /**
     * Batas waktu pembayaran gateway.
     *
     * Disimpan di `payment_expired_at` dan ikut dikirim ke provider, karena perintah
     * `wallet:expire-topups` hanya boleh menutup permintaan yang memang tidak bisa
     * dibayar lagi.
     */
    public const PAYMENT_TTL_MINUTES = 1440;

    /** Selisih nominal (rupiah) yang masih dianggap sama — pembulatan provider. */
    public const AMOUNT_TOLERANCE = 0.01;

    /** Tipe notifikasi internal modul. */
    public const NOTIFY_AWAITING_VERIFICATION = 'topup_awaiting_verification';

    public const NOTIFY_COMPLETED = 'topup_completed';

    public const NOTIFY_REJECTED = 'topup_rejected';

    public const NOTIFY_PAYMENT_PENDING = 'topup_payment_pending';

    public const NOTIFY_PAYMENT_FAILED = 'topup_payment_failed';

    public const NOTIFY_NEEDS_RECONCILE = 'topup_needs_reconciliation';

    public function __construct(
        private readonly WalletNumberingService $numbering,
        private readonly WalletSettingsService $settings,
        private readonly WalletLedgerService $ledger,
        private readonly WalletNotificationService $notifications,
        private readonly PaymentGatewayManager $gateways,
    ) {
    }

    // =====================================================================
    // Baca
    // =====================================================================

    /**
     * Daftar top up (layar admin) + filter status, metode, wallet, provider,
     * nomor, dan rentang tanggal.
     */
    public function query(Request $request): Builder
    {
        return $this->baseQuery()
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('payment_status'), fn (Builder $q) => $q->where('payment_status', $request->string('payment_status')->toString()))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', $request->string('method')->toString()))
            ->when($request->filled('wallet_id'), fn (Builder $q) => $q->where('wallet_id', (int) $request->input('wallet_id')))
            ->when($request->filled('provider'), fn (Builder $q) => $q->where('provider', $request->string('provider')->toString()))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('number', 'like', "%$term%")
                        ->orWhereHas('wallet', fn (Builder $w) => $w->where('number', 'like', "%$term%"));
                });
            });
    }

    /**
     * Antrean verifikasi petugas — layar "Top Up Pending" (§13), FIFO: yang paling
     * lama menunggu muncul lebih dulu.
     */
    public function awaitingVerificationQuery(): Builder
    {
        return $this->baseQuery()
            ->whereIn('status', WalletTopUp::VERIFYABLE_STATUSES)
            ->reorder('id');
    }

    /** Satu baris lengkap siap tampil (relasi dimuat, tanpa N+1 di daftar). */
    public function withDetails(WalletTopUp $topup): WalletTopUp
    {
        return $topup->load($this->eagerLoads());
    }

    /**
     * Rekening tujuan setoran manual (§12) — dibaca dari `wallet_bank_accounts`
     * ber-purpose topup, bukan dari konstanta kode.
     *
     * @return list<array<string, mixed>>
     */
    public function destinationAccounts(): array
    {
        return WalletBankAccount::query()
            ->forPurpose(WalletBankAccount::PURPOSE_TOPUP)
            ->with('bank:id,name,code')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (WalletBankAccount $account): array => [
                'id' => $account->id,
                'bank_name' => $account->bank?->name,
                'bank_code' => $account->bank?->code,
                'account_number' => $account->account_number,
                'account_name' => $account->account_name,
                'label' => $account->label(),
                'instructions' => $account->instructions,
            ])
            ->all();
    }

    // =====================================================================
    // Pengajuan
    // =====================================================================

    /**
     * Top up lewat payment gateway (§11).
     *
     * Urutan penting: nomor dokumen dibuat lebih dulu (ia menjadi `order_id` di sisi
     * provider), lalu transaksi dibuat, DAN BARU baris top up disimpan. Kegagalan
     * gateway dengan demikian tidak meninggalkan baris "hantu" di riwayat pengguna.
     *
     * @param  array<string, mixed>  $data
     * @return array{topup: WalletTopUp, gateway: array<string, mixed>}
     */
    public function requestGateway(Wallet $wallet, array $data, User $actor): array
    {
        $this->assertWalletUsable($wallet);

        $amount = round((float) ($data['amount'] ?? 0), 2);

        $this->assertAmountInRange($amount);

        if (! (bool) $this->settings->get('gateway_enabled', false)) {
            throw ValidationException::withMessages([
                'method' => 'Top up melalui payment gateway belum diaktifkan pada pengaturan modul wallet. '
                    .'Silakan gunakan transfer manual.',
            ]);
        }

        $provider = trim((string) ($data['provider'] ?? PaymentGatewayManager::DEFAULT_PROVIDER));

        $gateway = $this->configuredGateway($provider);

        $number = $this->numbering->next(WalletNumberingService::CODE_TOPUP);
        $expiresAt = Carbon::now()->addMinutes(self::PAYMENT_TTL_MINUTES);

        $payment = $this->createGatewayPayment($gateway, $wallet, $number, $amount, $actor, $expiresAt);

        $topup = WalletTopUp::create([
            'number' => $number,
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'method' => WalletTopUp::METHOD_GATEWAY,
            'status' => WalletTopUp::STATUS_PENDING_PAYMENT,
            'payment_status' => WalletTopUp::PAYMENT_PENDING,
            'provider' => $provider,
            'provider_reference' => $payment['provider_reference'],
            'payment_payload' => [
                'token' => $payment['token'],
                'redirect_url' => $payment['redirect_url'],
                'order_id' => $payment['reference'],
                'gross_amount' => $payment['amount'],
                'requested_on' => Carbon::now()->toIso8601String(),
                'raw' => $payment['raw'],
            ],
            'payment_expired_at' => $expiresAt,
            'requested_by' => $actor->id,
        ]);

        return [
            'topup' => $this->withDetails($topup),
            'gateway' => [
                'provider' => $provider,
                'order_id' => $payment['reference'],
                'snap_token' => $payment['token'],
                'redirect_url' => $payment['redirect_url'],
                'gross_amount' => $payment['amount'],
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ];
    }

    /**
     * Top up transfer manual (§12).
     *
     * Bukti transfer disimpan di disk `public` dengan nama acak bertimestamp (pola
     * modul PPDB) — yang masuk kolom hanyalah path relatif, URL dibuat saat tampil
     * sehingga disk bisa dipindah tanpa menambal data.
     *
     * @param  array<string, mixed>  $data  boleh memuat `proof_file` (UploadedFile)
     */
    public function requestManual(Wallet $wallet, array $data, User $actor): WalletTopUp
    {
        $this->assertWalletUsable($wallet);

        $amount = round((float) ($data['amount'] ?? 0), 2);

        $this->assertAmountInRange($amount);

        $proof = ($data['proof_file'] ?? null) instanceof UploadedFile
            ? $this->storeProof($data['proof_file'], $wallet)
            : null;

        try {
            $topup = WalletTopUp::create([
                'number' => $this->numbering->next(WalletNumberingService::CODE_TOPUP),
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'method' => WalletTopUp::METHOD_MANUAL,
                'status' => WalletTopUp::STATUS_AWAITING_VERIFICATION,
                'payment_status' => WalletTopUp::PAYMENT_PENDING,
                'bank_id' => $data['bank_id'] ?? null,
                'source_account_number' => $this->nullableText($data['source_account_number'] ?? null, 50),
                'source_account_name' => $this->nullableText($data['source_account_name'] ?? null, 100),
                'transferred_on' => $data['transferred_on'] ?? null,
                'proof_path' => $proof,
                'requested_by' => $actor->id,
            ]);
        } catch (Throwable $exception) {
            // Berkas tidak boleh yatang sendirian bila baris permintaannya gagal.
            if ($proof !== null) {
                Storage::disk('public')->delete($proof);
            }

            throw $exception;
        }

        $topup = $this->withDetails($topup);

        $this->notifyVerificationQueue($topup);

        return $topup;
    }

    // =====================================================================
    // Keputusan petugas
    // =====================================================================

    /**
     * Setujui top up: catat ledger, pindahkan saldo, selesaikan permintaan — atomik (§13, §39).
     *
     * Kewaspadaan anti post ganda: hanya berjalan bila permintaan masih menunggu
     * (`awaiting_verification`/`pending_payment`) DAN belum pernah tercatat di ledger
     * (`transaction_id` kosong, `payment_status` belum `paid`). Pemeriksaan itu dibaca
     * ulang pada baris yang DIKUNCI di dalam transaksi yang sama, sehingga pemanggilan
     * beruntun — petugas menekan "Approve" bersamaan dengan webhook — tetap
     * menghasilkan tepat satu kredit.
     *
     * Untuk top up gateway, status ditanya ulang ke penyedia lewat `checkStatus()`
     * SEBELUM transaksi dibuka: permission `wallet.topup.verify` tidak boleh bisa
     * melunasi tagihan yang belum dibayar, dan panggilan jaringan tidak boleh
     * menahan kunci database.
     */
    public function verify(WalletTopUp $topup, User $actor, ?string $note = null, ?Carbon $paidAt = null): WalletTopUp
    {
        $this->assertGatewayPaymentRecorded($topup);

        $settled = DB::transaction(function () use ($topup, $actor, $note, $paidAt): WalletTopUp {
            $locked = $this->lockTopUp((int) $topup->id);

            $this->assertVerifiable($locked);

            $transaction = $this->ledger->post(
                $this->walletOf($locked),
                WalletTransaction::TYPE_TOPUP,
                round((float) $locked->amount, 2),
                [
                    'reference' => $locked,
                    'description' => $this->ledgerDescription($locked),
                    'metadata' => $this->ledgerMetadata($locked, $note),
                    'created_by' => $locked->requested_by,
                    'approved_by' => $actor->id,
                ],
            );

            $locked->forceFill([
                'status' => WalletTopUp::STATUS_COMPLETED,
                'payment_status' => WalletTopUp::PAYMENT_PAID,
                'paid_at' => $paidAt ?? $this->paymentTime($locked),
                'verified_at' => Carbon::now(),
                'verified_by' => $actor->id,
                'transaction_id' => $transaction->id,
            ])->save();

            return $locked;
        });

        $this->notifyCompleted($settled);

        return $this->withDetails($settled);
    }

    /**
     * Tolak pengajuan top up. Alasan wajib, dan saldo tidak disentuh sama sekali (§12, §13).
     */
    public function reject(WalletTopUp $topup, User $actor, string $reason): WalletTopUp
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penolakan wajib diisi (minimal 5 karakter) agar pemilik wallet tahu apa yang harus diperbaiki.',
            ]);
        }

        $rejected = DB::transaction(function () use ($topup, $actor, $reason): WalletTopUp {
            $locked = $this->lockTopUp((int) $topup->id);

            $this->assertRejectable($locked);

            $locked->forceFill([
                'status' => WalletTopUp::STATUS_REJECTED,
                // Pembayaran yang belum pernah sah dinyatakan gagal; status yang lebih
                // maju (mis. refunded) sengaja tidak diutak-atik.
                'payment_status' => in_array($locked->payment_status, [
                    WalletTopUp::PAYMENT_UNPAID, WalletTopUp::PAYMENT_PENDING,
                ], true) ? WalletTopUp::PAYMENT_FAILED : $locked->payment_status,
                'verified_at' => Carbon::now(),
                'verified_by' => $actor->id,
                'rejection_reason' => Str::limit($reason, 500, ''),
            ])->save();

            return $locked;
        });

        $this->notifyRejected($rejected, $reason);

        return $this->withDetails($rejected);
    }

    // =====================================================================
    // Webhook (§11, §30)
    // =====================================================================

    /**
     * Proses notifikasi penyedia pembayaran — idempoten dan tidak pernah melempar
     * untuk penolaksi bisnis.
     *
     * Keluaran selalu `array{status, message, ...}` supaya controller bisa membalas
     * 200 untuk setiap keadaan "tidak bisa diproses": penyedia yang menerima 4xx/5xx
     * akan mengirim ulang beruntun, dan pengulangan itu keadaan yang sama.
     *
     * `status`: invalid_signature | unsupported | ignored | not_found | stale |
     *           amount_mismatch | rejected | processed.
     *
     * @return array<string, mixed>
     */
    public function handleWebhook(string $provider, Request $request): array
    {
        $gateway = $this->gateways->find($provider);

        if ($gateway === null) {
            return [
                'status' => 'unsupported',
                'message' => "Payment gateway \"$provider\" tidak dikenal.",
            ];
        }

        if (! $gateway->verifySignature($request)) {
            Log::warning('Notifikasi wallet ditolak: tanda tangan tidak cocok.', [
                'provider' => $provider,
                'x_route' => $request->header('X-Route'),
            ]);

            return ['status' => 'invalid_signature', 'message' => 'Tanda tangan notifikasi tidak valid.'];
        }

        $notification = $gateway->handleWebhook($request);

        try {
            return $this->applyPaymentNotification($provider, $notification);
        } catch (ValidationException $exception) {
            // Penolakan bisnis (mis. wallet diblokir setelah pembayaran masuk):
            // notifikasi diakui 200 supaya provider berhenti mengirim ulang, tetapi
            // saldo tidak ditambah dan petugas dikabari — bukan hilang diam-diam.
            Log::warning('Notifikasi pembayaran wallet tidak dapat dibukukan.', [
                'provider' => $provider,
                'errors' => $exception->errors(),
            ]);

            $this->notifyNeedsReconciliation($provider, $notification, $this->flattenError($exception));

            return [
                'status' => 'rejected',
                'message' => 'Notifikasi diterima tetapi kredit tidak dapat dibukukan: '.$this->flattenError($exception),
            ];
        }
    }

    /**
     * Terapkan satu notifikasi yang sudah ternormalisasi oleh gateway.
     *
     * Publik karena rekonsiliasi lain (mis. perintah penanya status) butuh pintu yang
     * sama; tidak ada panggilan HTTP nyata di dalamnya.
     *
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    public function applyPaymentNotification(string $provider, array $notification): array
    {
        $reference = trim((string) ($notification['reference'] ?? ''));
        $providerReference = trim((string) ($notification['provider_reference'] ?? ''));
        $status = trim((string) ($notification['status'] ?? ''));

        if ($reference === '' && $providerReference === '') {
            return ['status' => 'ignored', 'message' => 'Notifikasi tanpa nomor transaksi diabaikan.'];
        }

        if (! in_array($status, WalletTopUp::PAYMENT_STATUSES, true)) {
            return ['status' => 'ignored', 'message' => "Status pembayaran \"$status\" belum dikenali."];
        }

        $topup = $this->topupForCallback($provider, $reference, $providerReference);

        if ($topup === null) {
            return [
                'status' => 'not_found',
                'message' => 'Top up dengan nomor '.($reference !== '' ? $reference : $providerReference).' tidak ditemukan.',
            ];
        }

        if ($topup->method !== WalletTopUp::METHOD_GATEWAY) {
            return [
                'status' => 'ignored',
                'message' => "Top up {$topup->number} diajukan dengan transfer manual sehingga tidak menerima notifikasi gateway.",
            ];
        }

        // Gerbang cepat sebelum mengunci baris; keputusan final tetap di dalam transaksi.
        if (WalletTopUp::paymentRankOf($topup->payment_status) >= WalletTopUp::paymentRankOf($status)) {
            return [
                'status' => 'stale',
                'message' => "Pembayaran sudah {$topup->payment_status}; notifikasi \"$status\" diabaikan.",
                'topup_id' => $topup->id,
            ];
        }

        return $status === WalletTopUp::PAYMENT_PAID
            ? $this->settleFromNotification($topup, $notification, $provider, $providerReference)
            : $this->recordUnpaidNotification($topup, $status, $provider, $providerReference);
    }

    /**
     * Kedaluwarsakan permintaan gateway yang lewat batas waktu tanpa pembayaran.
     *
     * Idempoten dan tidak menyentuh ledger: hanya `pending_payment` yang
     * `payment_expired_at`-nya sudah lewat, dan baris yang pembayarannya sudah lebih
     * maju (mis. `paid` yang webhooks-nya terlambat) dilewati beserta alasannya.
     *
     * @return array{scanned: int, expired: int, skipped: list<array{topup_id: int, number: string, reason: string}>}
     */
    public function expireOverdue(?int $walletId = null, int $limit = 500): array
    {
        $overdue = WalletTopUp::query()
            ->where('status', WalletTopUp::STATUS_PENDING_PAYMENT)
            ->whereNotNull('payment_expired_at')
            ->where('payment_expired_at', '<', Carbon::now())
            ->when($walletId !== null, fn (Builder $q) => $q->where('wallet_id', $walletId))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $expired = 0;

        /** @var list<array{topup_id: int, number: string, reason: string}> $skipped */
        $skipped = [];

        foreach ($overdue as $topup) {
            $result = DB::transaction(function () use ($topup): array {
                $locked = $this->lockTopUp((int) $topup->id);

                if ($locked->status !== WalletTopUp::STATUS_PENDING_PAYMENT) {
                    return ['done' => false, 'reason' => "status permintaan sudah {$locked->status}."];
                }

                if ($locked->transaction_id !== null) {
                    return ['done' => false, 'reason' => 'sudah tercatat pada ledger.'];
                }

                // `paid` dan `refunded` tidak pernah dihapus oleh pekerjaan harian;
                // `failed`/`expired` boleh, karena permintaannya memang belum tertutup.
                if (WalletTopUp::paymentRankOf($locked->payment_status)
                    >= WalletTopUp::paymentRankOf(WalletTopUp::PAYMENT_REFUNDED)) {
                    return [
                        'done' => false,
                        'reason' => "pembayaran sudah {$locked->payment_status}; perlu diputus manual (verifikasi atau pembatalan).",
                    ];
                }

                $locked->forceFill([
                    'status' => WalletTopUp::STATUS_EXPIRED,
                    'payment_status' => WalletTopUp::PAYMENT_EXPIRED,
                ])->save();

                return ['done' => true, 'reason' => ''];
            });

            if (! $result['done']) {
                $skipped[] = [
                    'topup_id' => (int) $topup->id,
                    'number' => (string) $topup->number,
                    'reason' => $result['reason'],
                ];

                continue;
            }

            $expired++;

            $this->notifyPaymentFailed($this->withDetails($topup), 'kedaluwarsa');
        }

        return ['scanned' => $overdue->count(), 'expired' => $expired, 'skipped' => $skipped];
    }

    // =====================================================================
    // Internal — penjaga keadaan
    // =====================================================================

    /** @return list<string> */
    private function eagerLoads(): array
    {
        return [
            'wallet:id,number,status,balance,held_balance,student_id,employee_id,guardian_id,user_id',
            'wallet.student:id,full_name,nis',
            'wallet.employee:id,full_name,nip',
            'wallet.guardian:id,full_name,phone,email',
            'wallet.user:id,name,email',
            'bank:id,name,code',
            'requestedBy:id,name',
            'verifiedBy:id,name',
            'transaction:id,transaction_number,type,direction,amount,balance_before,balance_after,posted_at',
        ];
    }

    private function baseQuery(): Builder
    {
        return WalletTopUp::query()->with($this->eagerLoads())->orderByDesc('id');
    }

    /** Baris top up terkunci — dasar idempotensi (§30, §39). */
    private function lockTopUp(int $id): WalletTopUp
    {
        return WalletTopUp::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function walletOf(WalletTopUp $topup): Wallet
    {
        return Wallet::query()->whereKey($topup->wallet_id)->firstOrFail();
    }

    /** Wallet blocked/closed tidak boleh menerima setoran (§21). */
    private function assertWalletUsable(Wallet $wallet): void
    {
        if ($wallet->canTransact()) {
            return;
        }

        $label = match ($wallet->status) {
            Wallet::STATUS_BLOCKED => 'diblokir',
            Wallet::STATUS_CLOSED => 'sudah ditutup',
            default => (string) $wallet->status,
        };

        throw ValidationException::withMessages([
            'wallet' => "Wallet {$wallet->number} $label sehingga tidak dapat menerima top up. Hubungi petugas wallet.",
        ]);
    }

    /** Range nominal dibaca dari pengaturan modul, bukan konstanta kode. */
    private function assertAmountInRange(float $amount): void
    {
        $min = round((float) $this->settings->get('topup_min_amount', 0), 2);
        $max = round((float) $this->settings->get('topup_max_amount', 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal top up harus lebih besar dari nol.']);
        }

        if ($min > 0 && round($amount - $min, 2) < -self::AMOUNT_TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal top up minimal '.$this->money($min).'.',
            ]);
        }

        if ($max > 0 && round($amount - $max, 2) > self::AMOUNT_TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal top up maksimal '.$this->money($max).'.',
            ]);
        }
    }

    /**
     * Provider yang benar-benar bisa dipakai sekarang, dengan pesan yang menjelaskan.
     *
     * @throws ValidationException 422 — bukan 500 — supaya frontend bisa menampilkan
     *                              alasannya dan menyarankan jalur manual.
     */
    private function configuredGateway(string $provider): PaymentGateway
    {
        try {
            $gateway = $this->gateways->resolve($provider);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        if (! $gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'provider' => "Pembayaran online ($provider) belum dikonfigurasi pada aplikasi ini. "
                    .'Pasang kredensial gateway pada .env atau ajukan top up dengan transfer manual.',
            ]);
        }

        return $gateway;
    }

    /**
     * Panggil provider. Setiap kegagalan teknis (jaringan, penolakan, kredensial
     * salah) dilaporkan sebagai 422 berkoncal, tanpa baris top up yang tertinggal.
     *
     * @return array<string, mixed>
     */
    private function createGatewayPayment(
        PaymentGateway $gateway,
        Wallet $wallet,
        string $number,
        float $amount,
        User $actor,
        Carbon $expiresAt,
    ): array {
        $wallet->loadMissing('student:id,full_name', 'employee:id,full_name', 'guardian:id,full_name', 'user:id,name');

        try {
            $payment = $gateway->createPayment([
                'reference' => $number,
                'amount' => $amount,
                'description' => "Top up wallet {$wallet->number} a.n. ".$wallet->ownerName(),
                'customer' => [
                    'name' => $actor->name,
                    'email' => $actor->email,
                    'phone' => $actor->phone ?? null,
                ],
                'items' => [[
                    'id' => $number,
                    'price' => (int) round($amount),
                    'quantity' => 1,
                    'name' => "Top up wallet {$wallet->number}",
                ]],
                'metadata' => [
                    'module' => 'wallet',
                    'wallet_id' => $wallet->id,
                    'wallet_number' => $wallet->number,
                    'topup_number' => $number,
                ],
                'expiry_minutes' => self::PAYMENT_TTL_MINUTES,
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        // Sisi provider melengkapi diri sendiri; kunci yang hilang diisi dari permintaan
        // kita supaya tidak ada kolom yang diam-diam null hanya karena provider lain
        // tidak mengirimkannya.
        return $payment + [
            'reference' => $number,
            'amount' => $amount,
            'token' => null,
            'redirect_url' => null,
            'provider_reference' => null,
            'expires_at' => $expiresAt->toIso8601String(),
            'raw' => [],
        ];
    }

    /** Simpan bukti transfer di disk public; path relatif dikembalikan. */
    private function storeProof(UploadedFile $file, Wallet $wallet): ?string
    {
        $extension = Str::lower((string) $file->getClientOriginalExtension());

        $name = Carbon::now()->format('YmdHis').'-'.Str::lower(Str::random(6)).($extension !== '' ? ".$extension" : '');

        $stored = $file->storeAs("wallet/topups/{$wallet->id}", $name, 'public');

        return is_string($stored) ? $stored : null;
    }

    /**
     * Sebelum menyetujui top up gateway, pastikan penyedia memang mencatat lunas.
     *
     * Dipanggil DI LUAR transaksi database: `checkStatus()` adalah panggilan
     * jaringan, dan transaksi tidak boleh menahan kunci selamanya menunggu provider
     * menjawab.
     */
    private function assertGatewayPaymentRecorded(WalletTopUp $topup): void
    {
        if ($topup->method !== WalletTopUp::METHOD_GATEWAY) {
            return;
        }

        if ($topup->payment_status === WalletTopUp::PAYMENT_PAID) {
            return;
        }

        $gateway = $this->gateways->find((string) $topup->provider);

        if ($gateway === null || ! $gateway->isConfigured()) {
            $provider = $topup->provider !== null && $topup->provider !== ''
                ? $topup->provider
                : 'yang tercatat pada permintaan ini';

            throw ValidationException::withMessages([
                'payment_status' => "Status pembayaran pada gateway tidak dapat diperiksa karena penyedia "
                    ."($provider) tidak tersedia. Tunggu notifikasi webhook atau aktifkan gateway lebih dulu.",
            ]);
        }

        try {
            $status = $gateway->checkStatus((string) $topup->number);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['payment_status' => $exception->getMessage()]);
        }

        $recorded = trim((string) ($status['status'] ?? ''));

        if ($recorded === '') {
            throw ValidationException::withMessages([
                'payment_status' => 'Gateway tidak memberikan status pembayaran untuk top up ini, sehingga kredit tidak dapat dibuat.',
            ]);
        }

        if ($recorded !== WalletTopUp::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'payment_status' => 'Pembayaran pada gateway belum berhasil (status terkini: '.$recorded.'). '
                    .'Saldo hanya bertambah setelah pembayaran benar-benar tercatat lunas.',
            ]);
        }
    }

    /** Penjaga "hanya sekali"; selalu dibaca ulang pada baris yang terkunci. */
    private function assertVerifiable(WalletTopUp $topup): void
    {
        if ($topup->transaction_id !== null || $topup->payment_status === WalletTopUp::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'topup' => "Top up {$topup->number} sudah tercatat pada ledger, sehingga tidak dapat diverifikasi dua kali.",
            ]);
        }

        if (! in_array($topup->status, [
            WalletTopUp::STATUS_AWAITING_VERIFICATION,
            WalletTopUp::STATUS_PENDING_PAYMENT,
        ], true)) {
            throw ValidationException::withMessages([
                'topup' => "Top up {$topup->number} tidak sedang menunggu verifikasi (status {$topup->status}).",
            ]);
        }

        if ($topup->method === WalletTopUp::METHOD_MANUAL && blank($topup->proof_path)) {
            throw ValidationException::withMessages([
                'proof' => 'Bukti transfer belum diunggah, sehingga pengajuan ini belum dapat disetujui.',
            ]);
        }

        if (! $this->walletOf($topup)->canTransact()) {
            throw ValidationException::withMessages([
                'wallet' => 'Wallet tujuan sedang tidak aktif sehingga saldo tidak dapat ditambah. Pulihkan status wallet lebih dulu.',
            ]);
        }
    }

    private function assertRejectable(WalletTopUp $topup): void
    {
        if ($topup->transaction_id !== null || $topup->payment_status === WalletTopUp::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'reason' => "Top up {$topup->number} sudah menambah saldo. Gunakan pembatalan ledger (reversal), bukan penolakan.",
            ]);
        }

        if (! in_array($topup->status, [
            WalletTopUp::STATUS_AWAITING_VERIFICATION,
            WalletTopUp::STATUS_PENDING_PAYMENT,
        ], true)) {
            throw ValidationException::withMessages([
                'reason' => "Top up {$topup->number} sudah final (status {$topup->status}) dan tidak dapat ditolak lagi.",
            ]);
        }
    }

    // =====================================================================
    // Internal — jalur notifikasi
    // =====================================================================

    /**
     * Kredit dari notifikasi `paid`.
     *
     * Yang dikreditkan adalah nominal PERMINTAAN, bukan nominal notifikasi: kalau
     * keduanya berbeda, uang tidak boleh masuk diam-diam — petugas yang rekonsiliasi.
     *
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    private function settleFromNotification(
        WalletTopUp $topup,
        array $notification,
        string $provider,
        string $providerReference,
    ): array {
        $notified = round((float) ($notification['amount'] ?? 0), 2);
        $expected = round((float) $topup->amount, 2);

        if ($notified > 0 && abs($notified - $expected) > self::AMOUNT_TOLERANCE) {
            Log::warning('Notifikasi pembayaran wallet ditolak: nominal tidak sama dengan permintaan.', [
                'topup_number' => $topup->number,
                'expected' => $expected,
                'notified' => $notified,
            ]);

            $this->notifyNeedsReconciliation($provider, $notification, 'Nominal pembayaran tidak sama dengan nominal permintaan.');

            return [
                'status' => 'amount_mismatch',
                'message' => 'Nominal pembayaran '.$this->money($notified).' tidak sama dengan permintaan '
                    .$this->money($expected).'; kredit ditahan sampai direkonsiliasi.',
                'topup_id' => $topup->id,
            ];
        }

        $result = DB::transaction(function () use ($topup, $notification, $provider, $providerReference): array {
            $locked = $this->lockTopUp((int) $topup->id);

            // Pemeriksaan ulang di balik kunci: dua notifikasi kembar yang datang
            // hampir bersamaan harus melihat peringkat yang sudah naik.
            if (WalletTopUp::paymentRankOf($locked->payment_status)
                >= WalletTopUp::paymentRankOf(WalletTopUp::PAYMENT_PAID)) {
                return ['topup' => $locked, 'credited' => false, 'amount' => 0.0];
            }

            $this->assertVerifiable($locked);

            // Nominal permintaan yang menjadi patokan, bukan nominal notifikasi.
            $credited = round((float) $locked->amount, 2);

            $transaction = $this->ledger->post(
                $this->walletOf($locked),
                WalletTransaction::TYPE_TOPUP,
                $credited,
                [
                    'reference' => $locked,
                    'description' => $this->ledgerDescription($locked),
                    'metadata' => $this->ledgerMetadata($locked, null) + [
                        'notified_amount' => round((float) ($notification['amount'] ?? 0), 2),
                    ],
                    'created_by' => $locked->requested_by,
                ],
            );

            $locked->forceFill(array_filter([
                'provider' => $locked->provider ?? $provider,
                'provider_reference' => $this->freshProviderReference($locked, $providerReference),
                'status' => WalletTopUp::STATUS_COMPLETED,
                'payment_status' => WalletTopUp::PAYMENT_PAID,
                'paid_at' => $this->paymentTime($locked),
                'verified_at' => Carbon::now(),
                'transaction_id' => $transaction->id,
            ], static fn (mixed $value): bool => $value !== null))->save();

            return ['topup' => $locked, 'credited' => true, 'amount' => $credited];
        });

        if (! $result['credited']) {
            return [
                'status' => 'stale',
                'message' => "Pembayaran {$topup->number} sudah diproses; notifikasi tambahan diabaikan.",
                'topup_id' => $topup->id,
            ];
        }

        $settled = $this->withDetails($result['topup']);

        $this->notifyCompleted($settled);

        return [
            'status' => 'processed',
            'message' => 'Pembayaran top up '.$settled->number.' diterima; saldo bertambah '.$this->money((float) $result['amount']).'.',
            'topup_id' => $settled->id,
            'transaction_id' => $settled->transaction_id,
        ];
    }

    /**
     * Catat kabar pembayaran yang bukan pelunasan (pending / expire / fail / refund).
     *
     * Tidak ada satu pun jalur di sini yang menyentuh ledger.
     *
     * @return array<string, mixed>
     */
    private function recordUnpaidNotification(
        WalletTopUp $topup,
        string $status,
        string $provider,
        string $providerReference,
    ): array {
        /** @var array{topup: WalletTopUp, changed: bool} $result */
        $result = DB::transaction(function () use ($topup, $status, $provider, $providerReference): array {
            $locked = $this->lockTopUp((int) $topup->id);

            if (WalletTopUp::paymentRankOf($locked->payment_status) >= WalletTopUp::paymentRankOf($status)) {
                return ['topup' => $locked, 'changed' => false];
            }

            $attributes = [
                'provider' => $locked->provider ?? $provider,
                'provider_reference' => $this->freshProviderReference($locked, $providerReference),
                'payment_status' => $status,
            ];

            // Permintaan ikut ditutup hanya bila memang belum ada apa pun di atasnya.
            if ($status === WalletTopUp::PAYMENT_EXPIRED && $locked->status === WalletTopUp::STATUS_PENDING_PAYMENT) {
                $attributes['status'] = WalletTopUp::STATUS_EXPIRED;
            }

            $locked->forceFill(array_filter($attributes, static fn (mixed $value): bool => $value !== null))->save();

            return ['topup' => $locked, 'changed' => true];
        });

        if (! $result['changed']) {
            return [
                'status' => 'stale',
                'message' => "Status pembayaran {$topup->payment_status} sudah lebih maju dari \"$status\"; notifikasi diabaikan.",
                'topup_id' => $topup->id,
            ];
        }

        $fresh = $this->withDetails($result['topup']);

        if (in_array($status, [WalletTopUp::PAYMENT_EXPIRED, WalletTopUp::PAYMENT_FAILED], true)) {
            $this->notifyPaymentFailed($fresh, $status === WalletTopUp::PAYMENT_EXPIRED ? 'kedaluwarsa' : 'gagal');
        }

        return [
            'status' => 'processed',
            'message' => "Status pembayaran top up {$fresh->number} dicatat: $status.",
            'topup_id' => $fresh->id,
        ];
    }

    /**
     * Cari permintaan target notifikasi.
     *
     * Urutan: (provider, provider_reference) — kunci idempotensi §30 — lalu
     * (provider, number), karena `order_id` yang kita kirim ke provider adalah nomor
     * permintaan kita sendiri. Pencocokan selalu menyertakan provider supaya
     * notifikasi dari satu penyedia tidak pernah mengubah pembayaran milik penyedia
     * lain.
     */
    private function topupForCallback(string $provider, string $reference, string $providerReference): ?WalletTopUp
    {
        if ($providerReference !== '') {
            $found = WalletTopUp::query()
                ->where('provider', $provider)
                ->where('provider_reference', $providerReference)
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        if ($reference !== '') {
            return WalletTopUp::query()->where('provider', $provider)->where('number', $reference)->first();
        }

        return null;
    }

    /** Jangan pernah menabrak unique (provider, provider_reference) milik baris lain. */
    private function freshProviderReference(WalletTopUp $topup, string $providerReference): ?string
    {
        if ($providerReference === '') {
            return null;
        }

        if ($topup->provider_reference === $providerReference) {
            return $topup->provider_reference;
        }

        $taken = WalletTopUp::query()
            ->where('provider', (string) ($topup->provider ?? ''))
            ->where('provider_reference', $providerReference)
            ->whereKeyNot($topup->getKey())
            ->exists();

        return $taken ? null : Str::limit($providerReference, 100, '');
    }

    // =====================================================================
    // Internal — notifikasi
    // =====================================================================

    /** @return array<string, mixed> */
    private function contextOf(WalletTopUp $topup): array
    {
        return [
            'wallet_id' => $topup->wallet_id,
            'reference_type' => WalletTopUp::class,
            'reference_id' => $topup->id,
            'amount' => round((float) $topup->amount, 2),
        ];
    }

    /**
     * Penerima pesan: peminta pengajuan + pemilik wallet bila ia punya akun dan
     * orangnya berbeda.
     *
     * @return list<int>
     */
    private function recipients(WalletTopUp $topup): array
    {
        $ids = array_values(array_filter([$topup->requested_by]));

        $ownerId = (int) (Wallet::query()->whereKey($topup->wallet_id)->value('user_id') ?? 0);

        if ($ownerId > 0 && ! in_array($ownerId, $ids, true)) {
            $ids[] = $ownerId;
        }

        return array_map('intval', $ids);
    }

    /** @param array<string, mixed> $notification */
    private function notifyNeedsReconciliation(string $provider, array $notification, string $reason): void
    {
        $reference = trim((string) ($notification['reference'] ?? ''));

        $this->notifications->toStaff(
            'wallet.topup.verify',
            self::NOTIFY_NEEDS_RECONCILE,
            'Notifikasi pembayaran perlu diperiksa',
            "Notifikasi dari $provider untuk $reference tidak dapat dibukukan: $reason",
            ['reference_type' => WalletTopUp::class, 'provider' => $provider],
        );
    }

    private function notifyVerificationQueue(WalletTopUp $topup): void
    {
        $this->notifications->toStaff(
            'wallet.topup.verify',
            self::NOTIFY_AWAITING_VERIFICATION,
            'Top up menunggu verifikasi',
            'Ada setoran '.$this->money((float) $topup->amount).' untuk wallet '
                .($topup->wallet?->number ?? '-').' yang perlu dicocokkan dengan rekening sekolah.',
            $this->contextOf($topup),
        );

        $this->notifyRecipient(
            $topup,
            self::NOTIFY_PAYMENT_PENDING,
            'Top up menunggu verifikasi',
            "Pengajuan {$topup->number} sudah diterima dan menunggu verifikasi petugas.",
        );
    }

    private function notifyCompleted(WalletTopUp $topup): void
    {
        $this->notifyRecipient(
            $topup,
            self::NOTIFY_COMPLETED,
            'Saldo wallet bertambah',
            'Top up '.$topup->number.' berhasil. Saldo wallet '
                .($topup->wallet?->number ?? '-').' bertambah '.$this->money((float) $topup->amount).'.',
        );
    }

    private function notifyRejected(WalletTopUp $topup, string $reason): void
    {
        $this->notifyRecipient(
            $topup,
            self::NOTIFY_REJECTED,
            'Top up ditolak',
            "Pengajuan {$topup->number} ditolak: $reason. Saldo tidak berubah.",
        );
    }

    private function notifyPaymentFailed(WalletTopUp $topup, string $label): void
    {
        $this->notifyRecipient(
            $topup,
            self::NOTIFY_PAYMENT_FAILED,
            'Pembayaran top up '.$label,
            "Pembayaran top up {$topup->number} $label. Saldo belum bertambah; silakan ajukan ulang.",
        );
    }

    private function notifyRecipient(WalletTopUp $topup, string $type, string $title, string $body): void
    {
        foreach ($this->recipients($topup) as $userId) {
            $this->notifications->toUser($userId, $type, $title, $body, $this->contextOf($topup));
        }
    }

    // =====================================================================
    // Internal — utilitas
    // =====================================================================

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function ledgerMetadata(WalletTopUp $topup, ?string $note, array $extra = []): array
    {
        return array_filter([
            'topup_number' => $topup->number,
            'method' => $topup->method,
            'provider' => $topup->provider,
            'provider_reference' => $topup->provider_reference,
            'bank' => $topup->bank?->name,
            'source_account_number' => $topup->source_account_number,
            'proof_path' => $topup->proof_path,
            'note' => blank($note) ? null : Str::limit(trim((string) $note), 500, ''),
        ] + $extra, static fn (mixed $value): bool => $value !== null);
    }

    private function ledgerDescription(WalletTopUp $topup): string
    {
        return $topup->method === WalletTopUp::METHOD_GATEWAY
            ? trim('Top up gateway '.($topup->provider ?? '').' '.$topup->number)
            : 'Top up manual (bukti transfer) '.$topup->number;
    }

    /** Waktu bayar: pakai yang tercatat pada permintaan, jangan mengarang. */
    private function paymentTime(WalletTopUp $topup): Carbon
    {
        foreach ([$topup->paid_at, $topup->transferred_on] as $moment) {
            if ($moment === null) {
                continue;
            }

            try {
                return Carbon::parse((string) $moment);
            } catch (Throwable) {
                continue;
            }
        }

        return Carbon::now();
    }

    private function money(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function flattenError(ValidationException $exception): string
    {
        return implode(' ', array_map(
            static fn (array $messages): string => implode(' ', $messages),
            $exception->errors(),
        ));
    }
}
