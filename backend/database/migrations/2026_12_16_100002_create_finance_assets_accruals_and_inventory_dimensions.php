<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aset tetap, akrual, dan dimensi persediaan pada baris jurnal.
 *
 * Ketiganya adalah bagian standar yang sebelumnya dinyatakan "belum ada modulnya".
 * Sekarang modulnya ada, dan sengaja dibuat sebagai lapisan TIPIS di atas jurnal:
 * penyusutan, akrual, dan mutasi persediaan tidak menyimpan buku besarnya sendiri
 * — semuanya bermuara ke `finance_transactions`/`finance_transaction_lines`, agar
 * tidak pernah ada dua angka untuk satu kenyataan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------- aset tetap
        Schema::create('finance_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('kode_aset', 30)->unique();
            $table->string('nama', 160);
            $table->string('kategori', 60)->nullable();
            $table->date('tanggal_perolehan');
            $table->decimal('harga_perolehan', 18, 2);
            $table->decimal('nilai_residu', 18, 2)->default(0);
            $table->unsignedSmallInteger('umur_bulan');
            $table->enum('metode', ['garis_lurus', 'saldo_menurun'])->default('garis_lurus');
            $table->foreignId('akun_aset_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('akun_akumulasi_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('akun_beban_depresiasi_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->string('kode_unit', 30)->nullable();
            $table->string('kode_dana', 30)->nullable();
            $table->decimal('penyusutan_bulanan', 18, 2)->default(0);
            $table->decimal('akumulasi_penyusutan', 18, 2)->default(0);
            $table->decimal('nilai_buku', 18, 2)->default(0);
            $table->enum('status', ['aktif', 'dilepas', 'dihapusbukukan'])->default('aktif');
            $table->date('tanggal_pelepasan')->nullable();
            $table->decimal('hasil_pelepasan', 18, 2)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'tanggal_perolehan']);
            $table->index('kode_unit');
            $table->foreign('kode_unit')->references('code')->on('finance_business_units')->restrictOnDelete();
            $table->foreign('kode_dana')->references('kode_dana')->on('finance_funds')->restrictOnDelete();
        });

        // Satu baris per aset per periode: kunci idempotensi penyusutan.
        Schema::create('finance_asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('finance_assets')->cascadeOnDelete();
            $table->string('periode', 7);
            $table->date('tanggal');
            $table->decimal('nilai', 18, 2);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'periode'], 'finance_asset_depreciation_unique');
            $table->index('periode');
        });

        // ---------------------------------------------------------------- akrual
        Schema::create('finance_accruals', function (Blueprint $table): void {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->date('tanggal');
            $table->date('tanggal_balik');
            $table->enum('jenis', ['pendapatan', 'beban']);
            $table->foreignId('akun_akrual_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('akun_lawan_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->decimal('jumlah', 18, 2);
            $table->string('deskripsi', 500)->nullable();
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->string('kode_unit', 30)->nullable();
            $table->string('kode_dana', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'tanggal_balik']);
            $table->index('tanggal');
        });

        // --------------------------------------------- dimensi persediaan baris
        Schema::table('finance_transaction_lines', function (Blueprint $table): void {
            // Baris jurnal yang menggerakkan persediaan menyebut barang & jumlahnya
            // langsung di barisnya, sehingga kartu persediaan dan buku besar tidak
            // dapat saling menyimpang.
            $table->string('kode_persediaan', 50)->nullable()->after('kode_dana');
            $table->decimal('kuantiti', 18, 4)->nullable()->after('kode_persediaan');

            $table->index('kode_persediaan');
        });
    }

    public function down(): void
    {
        Schema::table('finance_transaction_lines', function (Blueprint $table): void {
            $table->dropIndex(['kode_persediaan']);
            $table->dropColumn(['kode_persediaan', 'kuantiti']);
        });

        Schema::dropIfExists('finance_accruals');

        Schema::dropIfExists('finance_asset_depreciations');
        Schema::table('finance_assets', function (Blueprint $table): void {
            $table->dropForeign(['kode_unit']);
            $table->dropForeign(['kode_dana']);
        });
        Schema::dropIfExists('finance_assets');
    }
};
