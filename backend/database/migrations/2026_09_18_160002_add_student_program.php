<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A5 — Peminatan siswa.
 *
 * Menambahkan `students.program_id` (opsional) agar siswa bisa dikaitkan ke
 * program/peminatan (IPA, IPS, Bahasa). Dijaga dengan `hasColumn` karena kolom
 * yang sama juga sudah dibuat oleh migrasi fondasi kurikulum; dengan begitu
 * migrasi ini tetap aman dijalankan di database mana pun tanpa duplikasi kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('students', 'program_id')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('students', 'program_id')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
    }
};
