<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `student_attendances.recorded_by` dan `employee_attendances.recorded_by`
 * dibuat sebagai FK ke `employees`, padahal controller dan model
 * (`recorder(): BelongsTo<User>`) selalu mengisinya dengan ID AKUN pengguna.
 * Akibatnya: pelanggaran FK pada basis data yang menegakkannya, atau
 * pencatat yang salah orang bila ID kebetulan sama.
 *
 * Kolom dipindah ke `users` — satu-satunya arti yang dipakai kode, dan sama
 * dengan semua tabel pencatatan lain (Tahfiz, Asrama, Tugas, Jurnal).
 */
return new class extends Migration
{
    private const TABLES = ['student_attendances', 'employee_attendances'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['recorded_by']);
            });

            // Nilai lama yang bukan ID akun valid dikosongkan, bukan ditebak.
            DB::table($table)
                ->whereNotNull('recorded_by')
                ->whereNotIn('recorded_by', DB::table('users')->select('id'))
                ->update(['recorded_by' => null]);

            Schema::table($table, function (Blueprint $t) {
                $t->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['recorded_by']);
            });

            DB::table($table)
                ->whereNotNull('recorded_by')
                ->whereNotIn('recorded_by', DB::table('employees')->select('id'))
                ->update(['recorded_by' => null]);

            Schema::table($table, function (Blueprint $t) {
                $t->foreign('recorded_by')->references('id')->on('employees')->nullOnDelete();
            });
        }
    }
};
