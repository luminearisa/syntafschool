<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Validasi kode asrama/gedung/kamar sudah mengabaikan baris soft-deleted
 * (whereNull('deleted_at')), tetapi indeks unique di database tidak —
 * membuat ulang kode milik data yang dihapus lunak memicu 500. Di SQLite
 * indeksnya diubah menjadi partial unique index agar konsisten dengan
 * validasi; driver lain tetap memakai unique penuh (tidak mendukung WHERE).
 */
return new class extends Migration
{
    private const INDEXES = [
        ['table' => 'boarding_dormitories', 'name' => 'boarding_dormitories_code_unique', 'cols' => ['code']],
        ['table' => 'boarding_buildings', 'name' => 'boarding_buildings_dormitory_id_code_unique', 'cols' => ['dormitory_id', 'code']],
        ['table' => 'boarding_rooms', 'name' => 'boarding_rooms_dormitory_id_code_unique', 'cols' => ['dormitory_id', 'code']],
    ];

    private function rebuild(string $where): void
    {
        foreach (self::INDEXES as $index) {
            $cols = implode(', ', $index['cols']);
            DB::statement("DROP INDEX IF EXISTS {$index['name']}");
            DB::statement("CREATE UNIQUE INDEX {$index['name']} ON {$index['table']} ({$cols}){$where}");
        }
    }

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuild(' WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuild('');
        }
    }
};
