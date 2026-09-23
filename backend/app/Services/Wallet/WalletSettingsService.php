<?php

namespace App\Services\Wallet;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Konfigurasi modul wallet, disimpan di tabel `settings` yang sudah ada
 * (group `wallet`) — bukan tabel konfigurasi baru, dan bukan file .env, karena
 * kebijakan ini diubah oleh bendahara lewat halaman Settings (§32).
 *
 * Kunci yang dikenal dibatasi daftar ini; kunci asing ditolak agar salah ketik
 * di UI tidak menciptakan setting hantu yang tidak pernah dibaca kode.
 */
class WalletSettingsService
{
    public const GROUP = 'wallet';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_JSON = 'json';

    public const TYPE_STRING = 'string';

    /** @var array<string, array{0: string, 1: mixed, 2: string}> */
    public const DEFAULTS = [
        // §4A — pembuatan otomatis dibiarkan mati sebagai default; wallet baru
        // lahir saat petugas membuat manual atau saat pemilik pertama membutuhkannya.
        'auto_create_student' => [self::TYPE_BOOLEAN, false, 'Buatkan wallet otomatis untuk santri baru'],
        'auto_create_employee' => [self::TYPE_BOOLEAN, false, 'Buatkan wallet otomatis untuk karyawan baru'],
        'topup_min_amount' => [self::TYPE_INTEGER, 10000, 'Nominal top up terkecil'],
        'topup_max_amount' => [self::TYPE_INTEGER, 5000000, 'Nominal top up terbesar'],
        'withdrawal_enabled' => [self::TYPE_BOOLEAN, true, 'Buka fitur penarikan saldo'],
        'withdrawal_min_amount' => [self::TYPE_INTEGER, 50000, 'Nominal penarikan terkecil'],
        'withdrawal_max_amount' => [self::TYPE_INTEGER, 5000000, 'Nominal penarikan terbesar'],
        'gateway_enabled' => [self::TYPE_BOOLEAN, false, 'Aktifkan top up lewat payment gateway'],
        'adjustment_requires_approval' => [self::TYPE_BOOLEAN, true, 'Adjustment perlu disetujui petugas lain'],
        'negative_balance_allowed' => [self::TYPE_BOOLEAN, false, 'Bolehkan saldo minus (transaksi pembelian fase berikutnya)'],
    ];

    /**
     * Seluruh nilai efektif: default ditimpa baris `settings` yang ada.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Setting::query()
            ->where('group', self::GROUP)
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key')
            ->all();

        $values = [];
        foreach (self::DEFAULTS as $key => [$type, $default]) {
            $values[$key] = array_key_exists($key, $stored)
                ? $this->cast($type, $stored[$key], $default)
                : $default;
        }

        return $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Simpan sebagian nilai. Kunci tak dikenal ditolak.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values, bool $replace = false): array
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Pengaturan tidak dikenal: '.implode(', ', $unknown));
        }

        return DB::transaction(function () use ($values, $replace): array {
            foreach ($values as $key => $value) {
                [$type] = self::DEFAULTS[$key];

                Setting::query()->updateOrCreate(
                    ['key' => $this->columnKey($key), 'group' => self::GROUP],
                    ['value' => $this->serialize($type, $value), 'type' => $type],
                );
            }

            return $replace ? $this->all() : array_intersect_key($this->all(), $values);
        });
    }

    /** Baris disimpan dengan prefix modul agar tidak menabrak kunci modul lain. */
    protected function columnKey(string $key): string
    {
        return 'wallet.'.$key;
    }

    protected function serialize(string $type, mixed $value): string
    {
        return match ($type) {
            self::TYPE_BOOLEAN => $value ? '1' : '0',
            self::TYPE_JSON => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    protected function cast(string $type, mixed $value, mixed $default): mixed
    {
        return match ($type) {
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_JSON => json_decode((string) $value, true) ?? $default,
            default => (string) $value,
        };
    }
}
