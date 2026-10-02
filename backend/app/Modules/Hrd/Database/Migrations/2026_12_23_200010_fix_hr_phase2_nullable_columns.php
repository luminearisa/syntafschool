<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi dari sumber yang tidak mengirim kunci idempotensi (mis. entri manual
 * HR) harus tetap dapat dicatat — kolomnya karena itu boleh kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_attendance_events') && Schema::hasColumn('hr_attendance_events', 'idempotency_key')) {
            Schema::table('hr_attendance_events', function (Blueprint $table) {
                $table->string('idempotency_key', 191)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Kolom tetap nullable: mengembalikannya menjadi NOT NULL dapat gagal
        // pada baris yang sudah ada tanpa kunci idempotensi.
    }
};
