<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — dukungan analitik, laporan, dan kalibrasi penilai.
 *
 * * `sup_reports` diberi kolom dokumen: judul, format berkas, jumlah baris,
 *   kode verifikasi publik, dan tanda tangan (checksum + penanda tangan).
 * * `sup_calibration_sessions` diberi ambang selisih yang disepakati dan
 *   kesimpulan moderator; `sup_calibration_scores` diberi label indikator
 *   (untuk kasus yang tidak terikat indikator instrumen) dan asal kasus.
 * * `sup_program_evaluations` diberi tautan program→periode (tahun ajaran)
 *   supaya evaluasi CIPP dapat dibandingkan antar tahun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sup_reports', function (Blueprint $table): void {
            $table->string('title', 200)->nullable()->after('report_key');
            $table->string('format', 10)->default('JSON')->after('title');
            $table->unsignedInteger('rows_count')->nullable()->after('format');
            $table->string('verification_code', 24)->nullable()->unique()->after('checksum');
            $table->unsignedBigInteger('generated_by')->nullable()->after('requested_by');
        });

        Schema::table('sup_calibration_sessions', function (Blueprint $table): void {
            $table->decimal('threshold', 6, 2)->default(1.00)->after('instrument_version_id');
            $table->text('conclusion')->nullable()->after('note');
            $table->unsignedBigInteger('program_id')->nullable()->after('threshold');
            $table->unsignedBigInteger('finalized_by')->nullable()->after('created_by');
            $table->timestamp('finalized_at')->nullable()->after('finalized_by');
        });

        Schema::table('sup_calibration_scores', function (Blueprint $table): void {
            $table->string('indicator_label', 200)->nullable()->after('indicator_id');
            $table->string('case_code', 60)->nullable()->after('indicator_label');
            $table->string('round', 20)->default('INDIVIDUAL')->after('case_code');
        });

        Schema::table('sup_program_evaluations', function (Blueprint $table): void {
            $table->string('period', 60)->nullable()->after('model');
            $table->json('stakeholders')->nullable()->after('product');
            $table->timestamp('finalized_at')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('sup_reports', function (Blueprint $table): void {
            $table->dropColumn(['title', 'format', 'rows_count', 'verification_code', 'generated_by']);
        });

        Schema::table('sup_calibration_sessions', function (Blueprint $table): void {
            $table->dropColumn(['threshold', 'conclusion', 'program_id', 'finalized_by', 'finalized_at']);
        });

        Schema::table('sup_calibration_scores', function (Blueprint $table): void {
            $table->dropColumn(['indicator_label', 'case_code', 'round']);
        });

        Schema::table('sup_program_evaluations', function (Blueprint $table): void {
            $table->dropColumn(['period', 'stakeholders', 'finalized_at']);
        });
    }
};
