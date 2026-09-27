<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 4 / Transfer: idempotensi (§3) + approval berjenjang (§16).
 *
 * `idempotency_key`: pindah uang adalah operasi yang tidak boleh lahir dua kali
 * karena retry jaringan/klik ganda menghasilkan dua jurnal Dr-Cr atas nama yang
 * sama. Kunci ini milik klien (mis. nomor slip bendahara); unik per pembuat,
 * jadi permintaan ulang mengembalikan transfer yang sudah ada, bukan duplikatnya.
 *
 * `requires_secondary_approval`: matriks approval §16 mengukur nominal, bukan
 * hanya role — transfer kecil cukup disetujui manajer keuangan, transfer besar
 * menuntut penyetuju kedua yang berbeda. Ambang nominalnya ada di konfigurasi
 * (`transfer_approval_threshold`), dan keputusan berlakunya aturan disimpan pada
 * barisnya supaya jejak "aturan mana yang berlaku saat ini diajukan" tetap
 * terbaca walau ambang nanti diubah.
 *
 * `status` ikut dilebarkan dari enum ke string: §3 mencantumkan CANCELLED, dan
 * transfer yang sudah disetujui tetapi tidak jadi dieksekusi harus bisa ditutup
 * dengan alasan — bukan dihapus, dan bukan dibiarkan menggantung selamanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transfers', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('transfer_number');
            $table->boolean('requires_secondary_approval')->default(false)->after('status');
            $table->foreignId('secondary_approved_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_at');
            $table->timestamp('secondary_approved_at')->nullable()->after('secondary_approved_by');
            $table->string('cancel_reason', 500)->nullable()->after('reject_reason');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancel_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            $table->string('status', 20)->default('draft')->change();

            $table->unique(['created_by', 'idempotency_key'], 'finance_transfers_created_by_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_transfers', function (Blueprint $table) {
            $table->dropUnique('finance_transfers_created_by_idempotency_unique');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'completed'])->default('draft')->change();
            $table->dropColumn([
                'idempotency_key', 'requires_secondary_approval',
                'secondary_approved_by', 'secondary_approved_at',
                'cancel_reason', 'cancelled_by', 'cancelled_at',
            ]);
        });
    }
};
