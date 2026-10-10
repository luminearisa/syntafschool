<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen rapor di luar nilai per mapel:
 * - sikap (spiritual & sosial) beserta deskripsinya;
 * - daftar ekstrakurikuler yang diikuti;
 * - daftar prestasi;
 * - catatan kepala sekolah.
 *
 * Disimpan sebagai JSON agar bentuknya lentur dan tetap satu baris per rapor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            $table->json('attitude')->nullable();
            $table->json('extracurriculars')->nullable();
            $table->json('achievements')->nullable();
            $table->text('principal_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            $table->dropColumn(['attitude', 'extracurriculars', 'achievements', 'principal_note']);
        });
    }
};
