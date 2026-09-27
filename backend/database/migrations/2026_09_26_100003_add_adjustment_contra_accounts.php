<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjustment (§51) butuh akun lawan (contra) untuk menjaga jurnal seimbang, dan
 * opsional akun kas/bank konkret bila koreksi menyasar saldo subledger. Kolom
 * ini dipersisten agar konteks yang diberikan saat membuat adjustment tetap ada
 * saat diposting (pada request berbeda), bukan hilang di memori.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_adjustments', function (Blueprint $table): void {
            $table->foreignId('contra_account_id')->nullable()->after('account_id')->constrained('finance_accounts')->nullOnDelete();
            $table->foreignId('cash_account_id')->nullable()->after('cost_center_id')->constrained('finance_cash_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->after('cash_account_id')->constrained('finance_bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_adjustments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contra_account_id');
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
