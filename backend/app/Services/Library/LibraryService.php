<?php

namespace App\Services\Library;

use App\Models\Library;
use App\Models\LibraryOperatingHour;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hidup perpustakaan: pembuatan (beserta konfigurasi bawaan),
 * perubahan, perpindahan status, dan penghapusan yang terlindungi.
 */
class LibraryService
{
    public function __construct(
        protected LibrarySettingsService $settings,
        protected NumberingService $numbering,
        protected LibrarySetupService $setup,
    ) {
    }

    /**
     * Buat perpustakaan baru beserta konfigurasi & penomoran bawaannya,
     * sehingga perpustakaan langsung dapat dikonfigurasi tanpa langkah manual.
     */
    public function create(array $data): Library
    {
        return DB::transaction(function () use ($data): Library {
            $library = Library::create($data);

            $this->settings->ensureDefaults($library);
            $this->numbering->ensureDefaults($library);

            // Kolom `status` diisi default DB saat insert, sehingga model di
            // memori belum memuatnya sampai dibaca ulang.
            return $library->refresh();
        });
    }

    public function update(Library $library, array $data): Library
    {
        $library->update($data);

        return $library;
    }

    /**
     * Ubah status mengikuti peta transisi; aktivasi menuntut konfigurasi minimum.
     */
    public function transition(Library $library, string $status, ?string $reason = null): Library
    {
        if ($status === $library->status) {
            throw ValidationException::withMessages([
                'status' => "Perpustakaan sudah berstatus \"{$status}\".",
            ]);
        }

        if (! in_array($status, $library->allowedTransitions(), true)) {
            throw ValidationException::withMessages([
                'status' => "Status \"{$library->status}\" tidak dapat diubah ke \"{$status}\".",
            ]);
        }

        if ($status === Library::STATUS_ACTIVE) {
            $missing = $this->setup->missingRequirements($library);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'status' => 'Perpustakaan belum dapat diaktifkan. Lengkapi: '.implode(', ', $missing).'.',
                ]);
            }
        }

        $from = $library->status;
        $library->update(['status' => $status]);

        activity('library')
            ->event('LIBRARY_STATUS_CHANGED')
            ->performedOn($library)
            ->withProperties([
                'from' => $from,
                'to' => $status,
                'reason' => $reason,
            ] + $this->context())
            ->log("Status perpustakaan diubah dari {$from} ke {$status}");

        return $library;
    }

    /**
     * Hapus perpustakaan (soft delete) setelah memastikan tidak ada data
     * transaksional yang menggantung.
     */
    public function delete(Library $library): void
    {
        $dependencies = $this->dependencies($library);

        if ($dependencies !== []) {
            throw ValidationException::withMessages([
                'library' => 'Perpustakaan tidak dapat dihapus karena masih memiliki: '
                    .implode(', ', $dependencies).'. Arsipkan perpustakaan sebagai gantinya.',
            ]);
        }

        $library->delete();
    }

    /**
     * Ganti seluruh jam operasional sebuah perpustakaan (bulk replace).
     *
     * Beberapa baris untuk hari yang sama diperbolehkan — itulah cara
     * menyatakan beberapa interval dalam sehari.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncOperatingHours(Library $library, array $rows): void
    {
        DB::transaction(function () use ($library, $rows): void {
            LibraryOperatingHour::query()->where('library_id', $library->id)->delete();

            $now = now();

            LibraryOperatingHour::query()->insert(array_map(fn (array $row) => [
                'library_id' => $library->id,
                'day_of_week' => (int) $row['day_of_week'],
                'is_open' => (bool) ($row['is_open'] ?? false),
                'open_time' => ($row['is_open'] ?? false) ? ($row['open_time'] ?? null) : null,
                'close_time' => ($row['is_open'] ?? false) ? ($row['close_time'] ?? null) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows));
        });

        activity('library')
            ->event('LIBRARY_OPERATING_HOURS_UPDATED')
            ->performedOn($library)
            ->withProperties(['rows' => count($rows)] + $this->context())
            ->log('Jam operasional perpustakaan diperbarui');
    }

    /**
     * Dependensi yang melarang penghapusan.
     *
     * Tabel anggota/koleksi/peminjaman/reservasi baru ada pada Step berikutnya;
     * pemeriksaan memakai `Schema::hasTable` agar aturan ini tetap berlaku
     * begitu tabel tersebut dibuat, tanpa perlu mengubah kode ini.
     *
     * @return list<string>
     */
    public function dependencies(Library $library): array
    {
        $map = [
            'library_members' => 'anggota',
            'library_items' => 'koleksi',
            'library_loans' => 'transaksi peminjaman',
            'library_reservations' => 'reservasi',
        ];

        $found = [];

        foreach ($map as $table => $label) {
            if (Schema::hasTable($table) && DB::table($table)->where('library_id', $library->id)->exists()) {
                $found[] = $label;
            }
        }

        return $found;
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