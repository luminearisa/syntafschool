<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5 / Otomasi & Observability (§28, §29): daftar operasi Keuangan.
 *
 * Satu tabel untuk dua pertanyaan yang jawabannya selalu dicari orang yang sama
 * (bendahara / admin): "pekerjaan berat yang tadi jalan sampai mana?" (§28) dan
 * "barusan ada yang gagal, kapan, dokumen mana, kenapa, dan bisa diulang tidak?"
 * (§29). Keduanya dibedakan `kind`: `job` = pekerjaan yang di-queue dan punya
 * kemajuan; `incident` = satu peristiwa gagal yang tercatat di jalur nyata
 * (webhook, gateway, pengiriman notifikasi, scheduler). Memisahnya jadi dua
 * tabel hanya akan memaksa admin memeriksa dua tempat untuk satu pertanyaan.
 *
 * Kenapa bukan `failed_jobs` bawaan queue saja? Tabel itu milik worker dan
 * isinya trace PHP mentah; ia mencatat kegagalan setelah job hilang, tidak
 * mencatat pekerjaan yang sedang berjalan, dan tidak menyimpan maksud bisnis
 * (tagihan mana, periode apa, apakah aman diulang). `failed_jobs` tetap ada di
 * bawah sebagai forensik; baris di sini adalah register yang dibaca manusia.
 *
 * `payload` adalah alasan retry boleh dijanjikan: pekerjaan dijalankan ulang
 * dari argumen yang sama, dan service yang dipanggilnya idempoten (billing per
 * santri/paket/periode, invoice per billing, notifikasi per referensi). Maka
 * `retryable` adalah pernyataan, bukan harapan — ia hanya bernilai true untuk
 * jenis pekerjaan yang jalurnya sudah terbukti anti-duplikat.
 *
 * `reference_type`/`reference_id`/`reference_number` menjawab "which transaction"
 * tanpa memaksa satu relasi FK: subjeknya bisa payment, invoice, jurnal,
 * statement, atau dokumen yang belum dibuat (billing massal). Tanpa kolom ini,
 * kegagalan hanya bisa diceritakan, tidak bisa ditelusuri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_operations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->enum('kind', ['job', 'incident'])->default('job');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);

            // Kemajuan (§28): Queued → Processing → Completed/Failed.
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);

            // Dokumen yang menjadi subjek, bila ada satu yang pasti (§29).
            $table->string('reference_type', 160)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 60)->nullable();

            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->string('error_kind', 120)->nullable();
            $table->json('context')->nullable();
            $table->boolean('retryable')->default(false);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'status', 'created_at']);
            $table->index(['name', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_operations');
    }
};
