<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keberesan finansial / financial clearance (§12 Phase 5).
 *
 * Tabel ini menyimpan KEPUTUSAN, bukan uang. Tidak ada satu pun kolom saldo,
 * dan tidak ada FK ke jurnal: invoice, payment, refund, dan alokasi tetap satu-
 * satunya tempat angka keuangan hidup (§ "Jangan menyimpan saldo secara manual
 * sebagai sumber kebenaran"). Yang dibekukan di sini adalah hasil pemeriksaan
 * pada satu tanggal (`evaluated_on` + `checklist`), supaya alasan sebuah
 * "tidak boleh" masih bisa dibaca bulan depan, bukan hanya hari H.
 *
 * Tiga angka kolomnya (`outstanding_total`, `credit_total`, `blocking_count`)
 * adalah ringkasan dari checklist itu — dipilih sebagai kolom karena daftar
 * keberesan perlu diurutkan dan dijumlahkan di SQL, dan selalu ditulis ulang
 * bersama `checklist` dan `headline`-nya dalam satu transaksi, jadi semuanya
 * tidak bisa tertinggal satu sama lain.
 *
 * "Satu santri satu keberesan terbuka per keperluan" ditegakkan di service
 * dengan lock, bukan unique index: SQLite dan MySQL sama-sama tidak punya
 * partial unique index, sementara unique biasa akan melarang riwayat yang sah —
 * keberesan diblokir, lalu diajukan ulang setelah dibayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_clearances', function (Blueprint $table) {
            $table->id();
            $table->string('clearance_number', 30)->unique();
            // restrictOnDelete: sebuah keputusan audit tidak boleh ikut hilang
            // karena baris santrinya dihapus.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            // graduation | transfer | withdrawal | certificate | re_registration |
            // final_settlement (§12).
            $table->string('purpose', 40);
            $table->enum('status', ['pending', 'cleared', 'blocked', 'waived', 'approved'])->default('pending');
            // Potongan angka pemeriksaan terakhir. Sumbernya tetap AR: kolom ini
            // ditulis ulang setiap `checklist` ditulis ulang.
            $table->decimal('outstanding_total', 15, 2)->default(0);
            $table->decimal('credit_total', 15, 2)->default(0);
            $table->unsignedInteger('blocking_count')->default(0);
            $table->date('evaluated_on');
            // Daftar periksa §12: billing, piutang, pembayaran tertunda, refund,
            // deposit — masing-masing dengan baris tagihannya ("Show Outstanding",
            // bukan sekadar "Belum Lunas").
            $table->json('checklist');
            // Satu kalimat "kenapa belum boleh" hasil pemeriksaan terakhir. Ia ikut
            // ditulis ulang bersama `checklist` supaya daftar keberesan bisa
            // menjelaskan dirinya tanpa membaca JSON per baris.
            $table->string('headline', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            // Keputusan (approve/waive). WAIVED di sini berarti "layanan diberi
            // meski ada tanggungan" — penghapusan piutangnya sendiri tetap milik
            // workflow write-off (§21) yang punya jurnalnya sendiri.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'purpose', 'status']);
            $table->index(['status', 'evaluated_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_clearances');
    }
};
