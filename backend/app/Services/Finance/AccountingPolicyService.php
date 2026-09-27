<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceFeeType;
use Illuminate\Validation\ValidationException;

/**
 * Kebijakan akuntansi modul Finance (Phase 3 §7, §18, §20).
 *
 * Dua hal dipusatkan di sini supaya tidak menyebar ke service bisnis:
 *
 * 1. BASIS PENGAKUAN — accrual: pendapatan diakui saat invoice terbit (Piutang
 *    bertambah); cash: pendapatan baru diakui saat uang masuk. Pilihannya milik
 *    bendahara (Settings), bukan asumsi kode.
 * 2. PEMETAAN AKUN — kode COA untuk piutang, deposit, hutang, clearing gateway,
 *    write-off, ekuitas awal, dan pendapatan fallback dibaca dari settings.
 *    Service tidak lagi men-hard-code '1300'/'2100'/'2200' dan tidak diam-diam
 *    memposting ke akun yang salah kalau Bendahara mengganti bagan akun.
 *
 * Resolusi akun ditolak kalau tidak ada / nonaktif / bukan akun rincian, dengan
 * pesan yang menyebut kunci setting-nya — lebih baik gagal saat itu juga daripada
 * jurnal yatim di buku besar.
 */
class AccountingPolicyService
{
    public const KEY_RECEIVABLE = 'account_receivable_code';

    public const KEY_DEPOSIT = 'account_deposit_code';

    public const KEY_PAYABLE = 'account_payable_code';

    public const KEY_GATEWAY_CLEARING = 'account_gateway_clearing_code';

    public const KEY_WRITE_OFF = 'account_write_off_code';

    public const KEY_OPENING_EQUITY = 'account_opening_equity_code';

    public const KEY_REVENUE_FALLBACK = 'account_revenue_fallback_code';

    /** Cache resolusi per instance (satu request = satu pembacaan buku besar). */
    private array $resolved = [];

    public function __construct(
        private readonly FinanceSettingsService $settings,
    ) {}

    // ==================== Basis pengakuan ====================

    public function basis(): string
    {
        $basis = (string) $this->settings->get('accounting_basis', FinanceSettingsService::BASIS_ACCRUAL);

        return in_array($basis, FinanceSettingsService::BASES, true)
            ? $basis
            : FinanceSettingsService::BASIS_ACCRUAL;
    }

    public function isAccrual(): bool
    {
        return $this->basis() === FinanceSettingsService::BASIS_ACCRUAL;
    }

    public function isCashBasis(): bool
    {
        return ! $this->isAccrual();
    }

    /** true = gateway/VA ditahan di akun clearing sebelum dana masuk bank (§18). */
    public function usesGatewayClearing(): bool
    {
        return (bool) $this->settings->get('gateway_use_clearing_account', false);
    }

    /** Metode pembayaran yang dananya bisa tertahan di gateway. */
    public function isGatewayMethod(string $method): bool
    {
        return in_array($method, ['payment_gateway', 'virtual_account'], true);
    }

    // ==================== Pemetaan akun ====================

    public function receivable(?FinanceFeeType $feeType = null): FinanceAccount
    {
        if ($feeType?->receivable_account_id !== null) {
            return $this->feeTypeAccount((int) $feeType->receivable_account_id, $feeType, 'piutang');
        }

        return $this->bySettingKey(self::KEY_RECEIVABLE);
    }

    public function receivableAccountId(?FinanceFeeType $feeType = null): int
    {
        return (int) $this->receivable($feeType)->id;
    }

    public function deposit(): FinanceAccount
    {
        return $this->bySettingKey(self::KEY_DEPOSIT);
    }

    public function depositAccountId(): int
    {
        return (int) $this->deposit()->id;
    }

    public function payable(): FinanceAccount
    {
        return $this->bySettingKey(self::KEY_PAYABLE);
    }

    public function payableAccountId(): int
    {
        return (int) $this->payable()->id;
    }

    public function gatewayClearing(): FinanceAccount
    {
        return $this->bySettingKey(self::KEY_GATEWAY_CLEARING);
    }

    public function gatewayClearingAccountId(): int
    {
        return (int) $this->gatewayClearing()->id;
    }

    public function writeOffExpense(): FinanceAccount
    {
        return $this->bySettingKey(self::KEY_WRITE_OFF);
    }

    public function writeOffExpenseAccountId(): int
    {
        return (int) $this->writeOffExpense()->id;
    }

    public function openingEquity(): FinanceAccount
    {
        return $this->bySettingKey(self::KEY_OPENING_EQUITY);
    }

    public function openingEquityAccountId(): int
    {
        return (int) $this->openingEquity()->id;
    }

    /**
     * Akun pendapatan untuk satu item invoice: mapping fee type (§20) dulu,
     * baru pendapatan Lain-lain bila jenis tagihan memang tidak dipetakan.
     */
    public function revenueAccountId(?FinanceFeeType $feeType): int
    {
        if ($feeType?->account_id !== null) {
            return (int) $this->feeTypeAccount((int) $feeType->account_id, $feeType, 'pendapatan')->id;
        }

        return (int) $this->bySettingKey(self::KEY_REVENUE_FALLBACK)->id;
    }

    // ==================== Internal ====================

    /** Baca kode dari settings lalu resolusi akunnya. */
    protected function bySettingKey(string $key): FinanceAccount
    {
        $code = trim((string) $this->settings->get($key, ''));

        if ($code === '') {
            throw ValidationException::withMessages([
                $key => "Kebijakan akuntansi finance.{$key} belum diisi, padahal jurnal membutuhkan akun ini.",
            ]);
        }

        return $this->byCode($code, $key);
    }

    protected function byCode(string $code, string $settingKey): FinanceAccount
    {
        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $account = FinanceAccount::query()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $settingKey => "Akun COA {$code} (finance.{$settingKey}) tidak ditemukan. Perbaiki kebijakan akuntansi di Settings.",
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $settingKey => "Akun COA {$account->code} ({$account->name}) nonaktif, tidak bisa dipakai membukukan jurnal.",
            ]);
        }

        if (! $account->isLeaf()) {
            throw ValidationException::withMessages([
                $settingKey => "Akun COA {$account->code} adalah akun induk; jurnal hanya boleh ke akun rincian.",
            ]);
        }

        return $this->resolved[$code] = $account;
    }

    /**
     * Akun pemetaan milik satu jenis tagihan. Disimpan di settings sebagai id,
     * jadi validasinya: ada, aktif, daun.
     */
    protected function feeTypeAccount(int $accountId, FinanceFeeType $feeType, string $label): FinanceAccount
    {
        $cacheKey = 'fee:'.$feeType->id.':'.$label;

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $account = FinanceAccount::query()->whereKey($accountId)->first();

        if ($account === null || ! $account->is_active || ! $account->isLeaf()) {
            throw ValidationException::withMessages([
                'fee_type' => "Pemetaan akun {$label} jenis tagihan {$feeType->code} tidak valid: pilih akun rincian yang aktif.",
            ]);
        }

        return $this->resolved[$cacheKey] = $account;
    }

    /**
     * Daftar kunci kebijakan untuk dibaca UI/audit: basis aktif + akun COA yang
     * dipakai tiap peristiwa bisnis.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $codes = [
            'receivable' => self::KEY_RECEIVABLE,
            'deposit' => self::KEY_DEPOSIT,
            'payable' => self::KEY_PAYABLE,
            'gateway_clearing' => self::KEY_GATEWAY_CLEARING,
            'write_off' => self::KEY_WRITE_OFF,
            'opening_equity' => self::KEY_OPENING_EQUITY,
            'revenue_fallback' => self::KEY_REVENUE_FALLBACK,
        ];

        $configured = array_map(
            fn (string $key): string => trim((string) $this->settings->get($key, '')),
            $codes,
        );

        $accounts = FinanceAccount::query()
            ->whereIn('code', array_filter($configured))
            ->get(['id', 'code', 'name'])
            ->keyBy('code');

        return [
            'basis' => $this->basis(),
            'gateway_clearing_enabled' => $this->usesGatewayClearing(),
            'accounts' => array_map(fn (string $role, string $code) => [
                'setting_key' => $codes[$role],
                'code' => $code,
                'account_id' => $accounts->get($code)?->id,
                'account_name' => $accounts->get($code)?->name,
            ], array_keys($configured), array_values($configured)),
        ];
    }
}
