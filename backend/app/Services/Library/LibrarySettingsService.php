<?php

namespace App\Services\Library;

use App\Models\Library;
use App\Models\LibrarySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Konfigurasi key-value per perpustakaan.
 *
 * Semua aturan operasional (durasi pinjam, batas perpanjangan, denda, RFID)
 * adalah DATA. Menambah opsi baru cukup menambah entri di
 * `LibrarySetting::DEFAULTS` — bukan kolom baru.
 */
class LibrarySettingsService
{
    /**
     * Pastikan seluruh key bawaan tersedia (idempoten).
     */
    public function ensureDefaults(Library $library): void
    {
        $existing = LibrarySetting::query()
            ->where('library_id', $library->id)
            ->pluck('key')
            ->all();

        $rows = [];
        $now = now();

        foreach (LibrarySetting::DEFAULTS as $key => [$type, $group, $default, $description]) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            $rows[] = [
                'library_id' => $library->id,
                'key' => $key,
                'value' => $this->stringify($default, $type),
                'type' => $type,
                'group' => $group,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            LibrarySetting::query()->insert($rows);
        }
    }

    /**
     * Seluruh konfigurasi, dikelompokkan, dengan nilai yang sudah di-cast.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function grouped(Library $library): array
    {
        $rows = LibrarySetting::query()
            ->where('library_id', $library->id)
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return $rows
            ->groupBy('group')
            ->map(fn ($items) => $items->map(fn (LibrarySetting $s) => [
                'key' => $s->key,
                'value' => $s->typedValue(),
                'raw_value' => $s->value,
                'type' => $s->type,
                'group' => $s->group,
                'description' => $s->description,
            ])->values()->all())
            ->all();
    }

    public function get(Library $library, string $key, mixed $default = null): mixed
    {
        $row = LibrarySetting::query()
            ->where('library_id', $library->id)
            ->where('key', $key)
            ->first();

        return $row !== null ? $row->typedValue() : $default;
    }

    /**
     * Simpan sekumpulan `key => value`.
     *
     * Hanya key yang dikenal (`DEFAULTS`) yang diterima; nilai dinormalkan
     * sesuai tipe masing-masing sehingga tersimpan konsisten.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{0:mixed,1:mixed}>  key => [lama, baru]
     */
    public function update(Library $library, array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys(LibrarySetting::DEFAULTS));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => 'Kunci konfigurasi tidak dikenal: '.implode(', ', $unknown).'.',
            ]);
        }

        $this->ensureDefaults($library);

        $changes = [];

        DB::transaction(function () use ($library, $values, &$changes): void {
            foreach ($values as $key => $value) {
                $row = LibrarySetting::query()
                    ->where('library_id', $library->id)
                    ->where('key', $key)
                    ->first();

                if ($row === null) {
                    continue;
                }

                $before = $row->typedValue();
                $normalized = $this->stringify($value, $row->type);

                if ($row->value === $normalized) {
                    continue;
                }

                $row->update(['value' => $normalized]);
                $changes[$key] = [$before, $row->fresh()->typedValue()];
            }
        });

        if ($changes !== []) {
            activity('library')
                ->event('LIBRARY_SETTING_UPDATED')
                ->performedOn($library)
                ->withProperties(['changes' => $changes] + $this->context())
                ->log('Konfigurasi perpustakaan diperbarui');
        }

        return $changes;
    }

    /**
     * Ubah nilai menjadi representasi teks sesuai tipe.
     */
    public function stringify(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            LibrarySetting::TYPE_INTEGER => (string) (int) $value,
            LibrarySetting::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            LibrarySetting::TYPE_DECIMAL => (string) (float) $value,
            LibrarySetting::TYPE_JSON => is_string($value) ? $value : json_encode($value),
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function context(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return [
            'ip' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ];
    }
}