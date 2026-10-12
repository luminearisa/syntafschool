<?php

namespace App\Services\Profile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blok tampilan generik untuk Profil 360°. Frontend hanya perlu tahu empat
 * jenis blok (stats, kv, table, note) sehingga bagian baru cukup ditambah di backend.
 */
final class Block
{
    /**
     * @param  array<string, mixed>  $items  label => nilai | [nilai, format]
     * @return array<string, mixed>
     */
    public static function kv(string $title, array $items): array
    {
        $out = [];
        foreach ($items as $label => $value) {
            [$v, $format] = is_array($value) && array_key_exists(0, $value) ? [$value[0], $value[1] ?? 'text'] : [$value, 'text'];
            $out[] = ['label' => $label, 'value' => $v, 'format' => $format];
        }

        return ['type' => 'kv', 'title' => $title, 'items' => $out];
    }

    /**
     * @param  list<array{0: string, 1: mixed, 2?: string, 3?: string|null, 4?: string|null}>  $items  [label, nilai, format, tone, hint]
     * @return array<string, mixed>
     */
    public static function stats(array $items): array
    {
        return ['type' => 'stats', 'items' => array_map(fn (array $i): array => [
            'label' => $i[0], 'value' => $i[1], 'format' => $i[2] ?? 'number', 'tone' => $i[3] ?? null, 'hint' => $i[4] ?? null,
        ], $items)];
    }

    /**
     * Tabel dari baris yang sudah disiapkan pemanggil (gabungan antar tabel).
     * Kunci baris `_link` (opsional) = alamat halaman profil/halaman terkait.
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $columns  [kunci, label, format]
     * @param  iterable<array<string, mixed>|object>  $rows
     * @return array<string, mixed>
     */
    public static function table(string $title, array $columns, iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return [
            'type' => 'table',
            'title' => $title,
            'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'format' => $c[2] ?? 'text'], $columns),
            'rows' => $out,
            'total' => count($out),
        ];
    }

    /** @return array<string, mixed> */
    public static function note(string $text, string $tone = 'info'): array
    {
        return ['type' => 'note', 'text' => $text, 'tone' => $tone];
    }

    /**
     * Hitung baris per nilai sebuah kolom (mis. kehadiran per status).
     *
     * @param  array<string, mixed>  $where
     * @return array<string, int>
     */
    public static function countBy(string $table, array $where, string $column): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }
        foreach (array_keys($where) as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return [];
            }
        }
        $q = DB::table($table);
        foreach ($where as $col => $value) {
            is_array($value) ? $q->whereIn($col, $value === [] ? [-1] : $value) : $q->where($col, $value);
        }

        return $q->selectRaw("$column as k, count(*) as c")->groupBy($column)->pluck('c', 'k')->map(fn ($c) => (int) $c)->all();
    }

    /**
     * @param  array<string, mixed>  $where
     */
    public static function sum(string $table, array $where, string $column): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0.0;
        }
        foreach (array_keys($where) as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return 0.0;
            }
        }
        $q = DB::table($table);
        foreach ($where as $col => $value) {
            is_array($value) ? $q->whereIn($col, $value === [] ? [-1] : $value) : $q->where($col, $value);
        }

        return (float) $q->sum($column);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    public static function count(string $table, array $where = []): int
    {
        return ProfileTable::exists($table) ? self::tableCount($table, $where) : 0;
    }

    /** @param array<string, mixed> $where */
    private static function tableCount(string $table, array $where): int
    {
        foreach (array_keys($where) as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return 0;
            }
        }
        $q = DB::table($table);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        foreach ($where as $col => $value) {
            is_array($value) ? $q->whereIn($col, $value === [] ? [-1] : $value) : $q->where($col, $value);
        }

        return (int) $q->count();
    }

    /** Buang blok kosong (null) hasil ProfileTable pada tabel yang tidak ada. */
    public static function compact(array $blocks): array
    {
        return array_values(array_filter($blocks, fn ($b) => $b !== null));
    }
}
