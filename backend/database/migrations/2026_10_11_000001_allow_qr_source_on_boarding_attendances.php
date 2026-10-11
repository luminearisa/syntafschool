<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Izinkan `boarding_attendances.source = 'qr'` (scan kode QR kamera).
     *
     * Kolom didefinisikan sebagai enum('manual','bulk','rfid') — pada SQLite
     * enum dikompilasi menjadi CHECK constraint juga, jadi kolom diubah
     * menjadi string biasa di semua driver (Laravel ≥11 membangun ulang tabel
     * SQLite secara otomatis untuk `change()`).
     */
    public function up(): void
    {
        Schema::table('boarding_attendances', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('boarding_attendances', function (Blueprint $table): void {
            $table->enum('source', ['manual', 'bulk', 'qr', 'rfid'])->default('manual')->change();
        });
    }
};
