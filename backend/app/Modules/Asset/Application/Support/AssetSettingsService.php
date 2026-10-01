<?php

namespace App\Modules\Asset\Application\Support;

use App\Modules\Asset\Models\AssetSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Setelan modul: nilai bawaan dari `config/asset.php`, dapat ditimpa sekolah
 * lewat tabel `asset_settings` tanpa mengubah kode.
 *
 * Aturan yang "seharusnya dapat diubah" (ambang kapitalisasi, pola kode, jendela
 * pengingat) dibaca dari sini — bukan dipaku sebagai konstanta.
 */
class AssetSettingsService
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $row = Cache::remember(
            'asset-setting:'.$key,
            now()->addMinutes(10),
            fn () => AssetSetting::query()->where('key', $key)->first(),
        );

        $value = $row?->value;

        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }

        if ($value === null) {
            $value = config('asset.'.str_replace(['numbering.', 'reminders.', 'booking.', 'sla.', 'loan.', 'replacement.'], ['numbering.', 'reminders.', 'booking.', 'sla.', 'loan.', 'replacement.'], $key));
        }

        return $this->memo[$key] = $value ?? $default;
    }

    public function set(string $key, mixed $value, ?string $description = null, ?int $actorId = null): AssetSetting
    {
        $row = AssetSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value], 'description' => $description, 'updated_by' => $actorId],
        );

        Cache::forget('asset-setting:'.$key);
        unset($this->memo[$key]);

        return $row;
    }

    /** Ambang kapitalisasi: kategori lebih dulu, lalu setelan sekolah, lalu bawaan. */
    public function capitalizationThreshold(?object $category = null): string
    {
        $fromCategory = $category?->capitalization_threshold ?? null;

        if (is_string($fromCategory) && $fromCategory !== '') {
            return $fromCategory;
        }

        return (string) $this->get('capitalization.default_threshold', '1000000');
    }

    /** Jendela pengingat garansi (hari sebelum berakhir). */
    public function warrantyWindows(): array
    {
        $value = $this->get('reminders.warranty_days', [90, 60, 30]);

        return is_array($value) ? array_map('intval', $value) : [90, 60, 30];
    }
}
