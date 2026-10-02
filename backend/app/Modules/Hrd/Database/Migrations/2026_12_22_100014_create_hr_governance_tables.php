<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tata kelola: aturan validasi, kualitas data, catatan HR, impor, audit, notifikasi,
 * outbox integrasi (§51, §55–§57, §62, §63, §75).
 *
 * Dua tabel di sini yang menentukan sifat keseluruhan modul:
 *
 *  - `hr_audit_logs` **tidak dapat diubah** setelah ditulis. Modelnya menolak
 *    `update`/`delete`, dan tidak ada endpoint yang menyuntingnya. Audit yang bisa
 *    diedit bukan audit (§51).
 *  - `hr_integration_outbox` menyimpan **niat** mencatatkan sesuatu ke modul lain
 *    (Aset, Keuangan, Workflow, LMS, Presensi) lebih dulu, lalu dikerjakan ketika
 *    modul tujuannya ada. Inilah sebab modul HRD tidak menuntut seluruh modul ERP
 *    terpasang, dan tidak ada peristiwa yang hilang diam-diam (§66, §77).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Aturan yang dapat dikonfigurasi. `rule_type` menentukan siapa yang
         * memeriksa: `machine` = pemeriksa bawaan terdaftar (`handler`), `config`
         * = ambang/parameter yang dibaca pemeriksa.
         */
        Schema::create('hr_validation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 200);
            $table->string('category', 40)->default('integrity')->comment('integrity | completeness | compliance | expiry | hierarchy | configuration');
            $table->string('severity', 20)->default('warning')->comment('critical | warning | info');
            $table->string('entity_type', 60)->default('employee')->comment('employee | organization | position | assignment | contract | document | certification | transaction');
            $table->string('handler', 60)->nullable()->comment('Alias pemeriksa bawaan; kosong = aturan manual');
            $table->json('config')->nullable()->comment('Parameter aturan, mis. {"window_days":30}');
            $table->string('message', 300);
            $table->string('suggestion', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_detect')->default(true)->comment('Diikutkan pada pemindaian otomatis');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['entity_type', 'is_active']);
            $table->index('severity');
        });

        Schema::create('hr_data_quality_scans', function (Blueprint $table): void {
            $table->id();
            $table->string('trigger', 20)->default('manual')->comment('manual | scheduled');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable()->comment('Skor 0–100');
            $table->json('totals')->nullable()->comment('Jumlah temuan per tingkat keparahan dan per aturan');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('started_at');
        });

        Schema::create('hr_data_quality_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_id')->nullable()->constrained('hr_data_quality_scans')->nullOnDelete();
            $table->string('rule_code', 80);
            $table->string('entity_type', 60)->default('employee');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
            $table->string('severity', 20)->default('warning');
            $table->string('status', 30)->default('open')->comment('open | acknowledged | resolved | ignored');
            $table->string('message', 300);
            $table->json('details')->nullable();
            $table->string('fingerprint', 120)->unique()->comment('Mencegah temuan yang sama menumpuk setiap pemindaian');
            $table->dateTime('detected_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_notes', 400)->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['rule_code', 'status']);
            $table->index('employee_id');
        });

        /*
         * Catatan HR. Pemisahan `visibility` di sini bukan hiasan: catatan
         * kedisiplinan tidak boleh muncul di linimasa yang dilihat pegawai.
         */
        Schema::create('hr_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('note_type', 40)->default('general')->comment('general | performance | disciplinary | meeting | sensitive | handover');
            $table->string('visibility', 20)->default('hr')->comment('hr | manager | employee | private');
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'note_type']);
            $table->index('visibility');
        });

        Schema::create('hr_employee_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 30)->default('employees')->comment('employees | documents | certifications | positions');
            $table->string('file_path', 300);
            $table->string('original_name', 250)->nullable();
            $table->string('status', 30)->default('uploaded')->comment('uploaded | mapped | validated | importing | imported | failed | cancelled');
            $table->json('mapping')->nullable()->comment('Peta kolom berkas → kolom sistem');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('summary')->nullable();
            $table->string('error_path', 300)->nullable()->comment('Berkas CSV berisi baris bermasalah + saran perbaikan');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('hr_employee_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('hr_employee_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw')->nullable();
            $table->json('normalized')->nullable();
            $table->string('status', 30)->default('pending')->comment('pending | valid | invalid | imported | skipped | failed');
            $table->string('action', 20)->nullable()->comment('create | update | skip');
            $table->json('errors')->nullable()->comment('[{"field":"nip","value":"…","error":"…","suggestion":"…"}]');
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['import_id', 'status']);
            $table->unique(['import_id', 'row_number']);
        });

        /*
         * Jejak audit: siapa, apa, kapan, sebelum, sesudah, dari mana, mengapa.
         * Tidak ada kolom `updated_at` yang bermakna — baris audit tidak pernah
         * berubah setelah ditulis.
         */
        Schema::create('hr_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 80)->comment('employee.updated | document.verified | transaction.approved | ...');
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 80)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changed_fields')->nullable();
            $table->string('reason', 400)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 250)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable()->comment('Rujukan lunak ke hr_transactions');
            $table->string('batch_id', 80)->nullable()->comment('Pengelompokan operasi massal');
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('batch_id');
        });

        Schema::create('hr_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 80);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_role', 80)->nullable();
            $table->string('channel', 30)->default('database')->comment('database | mail | whatsapp | push');
            $table->string('title', 250);
            $table->text('body')->nullable();
            $table->string('status', 30)->default('pending')->comment('pending | sent | failed | read');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['event', 'status']);
            $table->index(['recipient_id', 'read_at']);
        });

        Schema::create('hr_integration_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 40)->comment('asset | finance | workflow | notification | attendance | lms | activity | clinic | inventory | document | user');
            $table->string('action', 80);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending | dispatched | skipped | failed');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['module', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_integration_outbox');
        Schema::dropIfExists('hr_notifications');
        Schema::dropIfExists('hr_audit_logs');
        Schema::dropIfExists('hr_employee_import_rows');
        Schema::dropIfExists('hr_employee_imports');
        Schema::dropIfExists('hr_notes');
        Schema::dropIfExists('hr_data_quality_issues');
        Schema::dropIfExists('hr_data_quality_scans');
        Schema::dropIfExists('hr_validation_rules');
    }
};
