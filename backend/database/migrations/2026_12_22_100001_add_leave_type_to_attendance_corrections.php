<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis izin pada pengajuan koreksi presensi.
     *
     * Tanpa kolom ini, koreksi ke status Sakit/Izin kehilangan jenis izinnya
     * saat disetujui — aturan `leave_type_id` yang diwajibkan di jalur presensi
     * biasa jadi bisa dilewati lewat jalur koreksi.
     */
    public function up(): void
    {
        if (Schema::hasColumn('attendance_corrections', 'leave_type_id')) {
            return;
        }

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->foreignId('leave_type_id')
                ->nullable()
                ->after('new_status_id')
                ->constrained('leave_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('attendance_corrections', 'leave_type_id')) {
            return;
        }

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
        });
    }
};
