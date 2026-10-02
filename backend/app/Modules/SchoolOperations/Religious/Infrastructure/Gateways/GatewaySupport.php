<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Utilitas gateway: deteksi tabel/kolom yang benar-benar ada dan delegasi ke
 * kontrak modul lain bila terpasang.
 *
 * Seluruh akses lintas modul bersifat tahan-gagal: bila sumber tidak tersedia,
 * gateway menyatakannya secara eksplisit alih-alih mengarang data (§94).
 */
trait GatewaySupport
{
    protected function has(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function columns(string $table): array
    {
        try {
            return Schema::getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Pilih kolom pertama yang tersedia dari daftar kandidat. */
    protected function pick(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function row(string $table, int $id): ?array
    {
        if (! $this->has($table)) {
            return null;
        }

        $row = DB::table($table)->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    /** Ambil kontrak modul lain bila benar-benar terpasang. */
    protected function delegate(string $contract): ?object
    {
        try {
            if (interface_exists($contract) && app()->bound($contract)) {
                return app($contract);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /** Ambil tabel pertama yang ada dari daftar kandidat nama tabel. */
    protected function firstTable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function mapIds(array $rows, callable $mapper): array
    {
        $out = [];
        foreach ($rows as $row) {
            $mapped = $mapper((array) $row);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }
}
