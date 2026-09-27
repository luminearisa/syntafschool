<?php

namespace App\Services\Boarding;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Konfigurasi modul boarding, disimpan di tabel `settings` yang sudah ada
 * (group `boarding`, kunci berprefix `boarding.`) — bukan tabel konfigurasi
 * baru dan bukan file .env, karena kebijakan ini diubah kepala asrama lewat
 * halaman Settings (§56, §73).
 *
 * Kunci yang dikenal dibatasi daftar DEFAULTS ini; kunci asing ditolak supaya
 * salah ketik di UI tidak menciptakan setting hantu yang tidak pernah dibaca
 * kode. Nilai di luar opsi yang diizinkan (mis. approval 3 langkah) juga
 * ditolak — ambang workflow harus tetap bisa dibaca frontend.
 */
class BoardingSettingsService
{
    public const GROUP = 'boarding';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_JSON = 'json';

    public const TYPE_STRING = 'string';

    /** @var array<string, array{0: string, 1: mixed, 2: string}> */
    public const DEFAULTS = [
        'point_label' => [self::TYPE_STRING, 'Poin Pelanggaran', 'Istilah poin yang dipakai di laporan & dashboard'],
        'point_threshold_warning' => [self::TYPE_INTEGER, 25, 'Ambang poin untuk teguran lisan (§37)'],
        'point_threshold_coaching' => [self::TYPE_INTEGER, 50, 'Ambang poin untuk pembinaan'],
        'point_threshold_parent_notification' => [self::TYPE_INTEGER, 75, 'Ambang poin untuk pemberitahuan orang tua'],
        // Workflow approval configurable (§27): 1 langkah untuk asrama kecil,
        // 2 langkah (wali kamar lalu kepala asrama) untuk asrama besar.
        'permission_approval_steps' => [self::TYPE_INTEGER, 1, 'Jumlah langkah persetujuan izin keluar (1 atau 2)'],
        'permission_checkin_grace_minutes' => [self::TYPE_INTEGER, 30, 'Tenggat setelah jam kembali sebelum dicatat pulang terlambat (§29)'],
        // RFID hanya konsumen kartu modul Wallet; mati = petugas absen manual.
        'rfid_attendance_enabled' => [self::TYPE_BOOLEAN, true, 'Izinkan absensi asrama lewat scan kartu RFID'],
    ];

    /** Opsi terbatas untuk kunci tertentu => list nilai yang boleh dipakai. */
    public const ALLOWED_VALUES = [
        'permission_approval_steps' => [1, 2],
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
            ->whereIn('key', array_map(
                fn (string $key): string => $this->columnKey($key),
                array_keys(self::DEFAULTS),
            ))
            ->pluck('value', 'key')
            ->all();

        $values = [];
        foreach (self::DEFAULTS as $key => [$type, $default]) {
            // Baris `settings` memakai kunci berprefix modul; `key` unik global,
            // jadi membaca tanpa prefix akan selalu jatuh ke nilai default.
            $column = $this->columnKey($key);

            $values[$key] = array_key_exists($column, $stored)
                ? $this->cast($type, $stored[$column], $default)
                : $default;
        }

        return $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::DEFAULTS)
            ? ($this->all()[$key] ?? $default)
            : $default;
    }

    /**
     * Simpan sebagian nilai. Kunci tak dikenal dan nilai di luar opsi ditolak.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values, bool $replace = false): array
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Pengaturan tidak dikenal: '.implode(', ', $unknown));
        }

        foreach ($values as $key => $value) {
            $this->assertAllowed($key, $value);
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
        return 'boarding.'.$key;
    }

    /**
     * Kunci dengan opsi terbatas (jumlah langkah approval) diverifikasi di sini.
     */
    protected function assertAllowed(string $key, mixed $value): void
    {
        $allowed = self::ALLOWED_VALUES[$key] ?? null;

        if ($allowed === null) {
            return;
        }

        if (! in_array((int) $value, $allowed, true)) {
            throw new \InvalidArgumentException(
                $key.' hanya menerima salah satu dari: '.implode(', ', array_map('strval', $allowed)),
            );
        }
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
