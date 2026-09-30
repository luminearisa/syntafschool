<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Modules\Supervisi\Models\SupSetting;

/**
 * Pengaturan modul supervisi.
 *
 * Nilai tersimpan menang atas nilai bawaan; sekolah dapat mengubah kebijakan
 * (frekuensi minimum, visibilitas hasil, bobot supervisi dalam penilaian
 * kinerja) tanpa deploy ulang.
 */
class SupervisiSettings
{
    /** @var array<string,mixed>|null cache per permintaan */
    protected ?array $cache = null;

    /**
     * Nomor revisi bersama: dinaikkan setiap kali pengaturan disimpan sehingga
     * seluruh instance (termasuk yang sudah hidup lebih dulu) membaca ulang.
     */
    protected static int $revision = 0;

    protected int $cachedRevision = -1;

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache === null || $this->cachedRevision !== self::$revision) {
            $stored = SupSetting::query()->get()->mapWithKeys(fn (SupSetting $setting) => [
                $setting->key => $setting->typedValue(),
            ])->all();

            $this->cache = array_merge(SupSetting::DEFAULTS, $stored);
            $this->cachedRevision = self::$revision;
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key, 0);
    }

    /** @param array<string,mixed> $values */
    public function putMany(array $values): array
    {
        foreach ($values as $key => $value) {
            $cast = $this->castFor($value);

            SupSetting::query()->updateOrCreate(
                ['school_id' => null, 'key' => $key],
                ['value' => $this->stringify($value, $cast), 'cast' => $cast],
            );
        }

        // Seluruh instance (termasuk yang sudah hidup lebih dulu) membaca ulang.
        self::$revision++;
        $this->cache = null;
        $this->cachedRevision = self::$revision;

        return $this->all();
    }

    /** Kebijakan frekuensi minimum per semester menurut keputusan desain A7. */
    public function minimumVisitsFor(string $employeeType): array
    {
        return match ($employeeType) {
            'kepala_sekolah' => ['full_observation' => $this->int('min_visits_kepala_sekolah')],
            'tendik', 'staf' => ['staff_review' => $this->int('min_visits_tendik')],
            default => [
                'full_observation' => $this->int('min_visits_guru_full_observation'),
                'walkthrough' => $this->int('min_visits_guru_walkthrough'),
            ],
        };
    }

    protected function castFor(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    protected function stringify(mixed $value, string $cast): string
    {
        return match ($cast) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
