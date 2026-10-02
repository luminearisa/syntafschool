<?php

namespace App\Modules\Hrd\Application\Support;

use App\Modules\Hrd\Models\HrSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Setelan modul (§81 Administration → HR Settings).
 *
 * Nilai bawaan dari `config/hrd.php`, dapat ditimpa per unit organisasi lewat
 * `hr_settings` tanpa mengubah kode. Semua aturan yang "seharusnya dapat diubah
 * sekolah" dibaca dari sini — jendela pengingat, pola penomoran, ambang validasi,
 * kebijakan retensi — sehingga tidak ada kebijakan yang terkubur sebagai konstanta.
 */
class HrdSettingsService
{
    /** @var array<string, mixed> */
    private array $memo = [];

    private int $ttlMinutes = 10;

    /**
     * Baca setelan: penimpaan pada unit organisasi → penimpaan global → konfigurasi.
     */
    public function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        $cacheKey = "hrd-setting:{$organizationId}:{$key}";

        if (array_key_exists($cacheKey, $this->memo)) {
            return $this->memo[$cacheKey];
        }

        $value = null;

        if ($organizationId !== null) {
            $value = $this->lookup($key, $organizationId);
        }

        $value ??= $this->lookup($key, null);

        if ($value === null) {
            $value = data_get(config('hrd'), $key, $default);
        }

        return $this->memo[$cacheKey] = $value ?? $default;
    }

    /** Nilai integer — kebanyakan setelan modul ini berupa jumlah hari/cacah. */
    public function integer(string $key, int $default = 0, ?int $organizationId = null): int
    {
        return (int) $this->get($key, $default, $organizationId);
    }

    public function boolean(string $key, bool $default = false, ?int $organizationId = null): bool
    {
        return (bool) $this->get($key, $default, $organizationId);
    }

    /**
     * Jendela pengingat (hari sebelum berakhir), selalu urut menurun supaya
     * pengingat H-90 dikirim sebelum H-30.
     *
     * @return list<int>
     */
    public function reminderWindows(string $kind, ?int $organizationId = null): array
    {
        $value = $this->get("reminders.{$kind}", [], $organizationId);

        if (! is_array($value)) {
            return [];
        }

        $windows = array_values(array_unique(array_map('intval', $value)));
        rsort($windows);

        return $windows;
    }

    /** Simpan penimpaan setelan (per unit organisasi bila diberikan). */
    public function set(string $key, mixed $value, ?int $organizationId = null, ?int $actorId = null, ?string $description = null): HrSetting
    {
        $setting = HrSetting::query()->updateOrCreate(
            ['key' => $key, 'organization_id' => $organizationId],
            ['value' => ['value' => $value], 'description' => $description, 'updated_by' => $actorId],
        );

        Cache::forget("hrd-setting:{$organizationId}:{$key}");
        unset($this->memo["hrd-setting:{$organizationId}:{$key}"]);

        return $setting;
    }

    /** Buang penimpaan sehingga nilai kembali ke konfigurasi. */
    public function forget(string $key, ?int $organizationId = null): void
    {
        HrSetting::query()->where('key', $key)->where('organization_id', $organizationId)->delete();

        Cache::forget("hrd-setting:{$organizationId}:{$key}");
        unset($this->memo["hrd-setting:{$organizationId}:{$key}"]);
    }

    /** Dipakai pengujian dan perintah artisan. */
    public function flushMemo(): void
    {
        $this->memo = [];
    }

    private function lookup(string $key, ?int $organizationId): mixed
    {
        $cacheKey = "hrd-setting:{$organizationId}:{$key}";

        $row = Cache::remember($cacheKey, now()->addMinutes($this->ttlMinutes), fn () => HrSetting::query()
            ->where('key', $key)
            ->where('organization_id', $organizationId)
            ->first());

        $value = $row?->value;

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }
}
