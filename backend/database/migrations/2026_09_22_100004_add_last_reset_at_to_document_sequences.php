<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom pendukung reset penomoran berkala.
 *
 * `document_sequences` (sistem penomoran existing) sudah punya `reset_period`
 * tetapi belum menyimpan kapan reset terakhir terjadi — tanpa itu reset tahunan
 * tidak dapat ditegakkan. Kolom ini additive dan tabelnya belum dipakai modul
 * lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->timestamp('last_reset_at')->nullable()->after('last_number');
        });
    }

    public function down(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropColumn('last_reset_at');
        });
    }
};