<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 Modul Supervisi — fondasi instrumen, supervisor, dan tata kelola.
 *
 * Semua tabel berprefix `sup_` dan bersifat **tambahan**: tidak ada tabel inti
 * yang diubah, sehingga migrasi aman dijalankan pada basis data yang sudah
 * berjalan.
 *
 * Kolom jenis/status memakai `string` (bukan `enum`) supaya penambahan jenis
 * baru tidak memerlukan ALTER TABLE — nilainya diikat oleh enum PHP di
 * `App\Modules\Supervisi\Domain\Enums`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Skala & ambang predikat ----------
        Schema::create('sup_grade_scales', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();          // default_kemendikbud, pkg, hr_1_5, ...
            $table->string('name', 120);
            $table->string('scale_type', 20)->default('PERCENT'); // PERCENT | RAW
            $table->json('bands');                          // [{min,max,label,tone,credit_percent}]
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);   // bawaan: tidak boleh dihapus
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ---------- Pustaka instrumen ----------
        Schema::create('sup_instruments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name', 180);
            $table->string('category', 30)->default('AKADEMIK');
            $table->string('target_type', 20)->default('GURU');
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);    // bawaan modul
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'is_active'], 'sup_instrument_category_idx');
            $table->index(['target_type', 'is_active'], 'sup_instrument_target_idx');
        });

        Schema::create('sup_instrument_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->constrained('sup_instruments')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('DRAFT');
            $table->string('scoring_mode', 20)->default('PERCENT');
            $table->string('scale_type', 20)->default('SCALE_1_4'); // SCALE_1_4 | SCALE_0_4 | SCALE_1_5 | YES_NO | SCALE_0_2 | MIXED
            $table->unsignedBigInteger('grade_scale_id')->nullable();
            $table->decimal('max_score', 10, 2)->nullable();       // hasil hitung, bukan isian manual
            $table->decimal('min_passing_score', 8, 2)->nullable();
            $table->boolean('requires_action_plan')->default(true);
            $table->boolean('allows_multi_source')->default(false);
            $table->text('guidance')->nullable();                  // cara umum memakai instrumen
            $table->string('checksum', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamps();

            $table->unique(['instrument_id', 'version'], 'sup_version_unique');
            $table->index(['status', 'published_at'], 'sup_version_status_idx');
        });

        Schema::create('sup_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('sup_instrument_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->decimal('weight', 8, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['version_id', 'sort_order'], 'sup_section_order_idx');
        });

        Schema::create('sup_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('sup_sections')->cascadeOnDelete();
            $table->string('code', 30)->nullable();
            $table->string('statement', 500);
            $table->text('guidance')->nullable();                  // rubrik/panduan penilaian
            $table->string('input_type', 20)->default('SCALE');
            $table->string('scale_code', 30)->nullable();          // untuk SCALE: SCALE_1_4, dst.

            // Untuk input_type NUMBER (entri angka).
            $table->decimal('score_min', 10, 2)->nullable();
            $table->decimal('score_max', 10, 2)->nullable();
            $table->decimal('score_step', 8, 2)->nullable();
            $table->unsignedTinyInteger('score_decimals')->default(2);
            $table->string('score_unit', 30)->nullable();          // %, menit, kali, ...
            $table->string('direction', 20)->default('HIGHER_BETTER');

            // Untuk input_type BOOLEAN.
            $table->decimal('score_yes', 8, 2)->nullable();
            $table->decimal('score_no', 8, 2)->nullable();
            $table->decimal('score_partial', 8, 2)->nullable();

            $table->boolean('is_scored')->default(true);
            $table->boolean('is_required')->default(true);
            $table->boolean('allow_na')->default(false);
            $table->boolean('is_critical')->default(false);        // bila gagal → status "belum memenuhi"
            $table->decimal('weight', 8, 2)->default(1);
            $table->decimal('min_comment_below', 8, 2)->nullable(); // komentar wajib bila skor di bawah ini
            $table->string('evidence_hint', 255)->nullable();       // bukti yang disarankan
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['section_id', 'sort_order'], 'sup_indicator_order_idx');
            $table->index('input_type', 'sup_indicator_input_idx');
        });

        Schema::create('sup_indicator_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('indicator_id')->constrained('sup_indicators')->cascadeOnDelete();
            $table->string('label', 180);
            $table->text('descriptor')->nullable();                // deskriptor rubrik level ini
            $table->decimal('score', 8, 2);
            $table->string('tone', 10)->default('GRAY');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['indicator_id', 'sort_order'], 'sup_option_order_idx');
        });

        // ---------- Registry supervisor (§B keputusan desain) ----------
        Schema::create('sup_supervisors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30)->default('KEPALA_SEKOLAH');
            $table->string('scope_type', 30)->nullable();          // SCHOOL | UNIT | SUBJECT | DEPARTMENT | DORM | PROGRAM
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('subject_ids')->nullable();               // mapel yang layak dinilai
            $table->json('categories')->nullable();                // kategori instrumen yang boleh dipakai
            $table->boolean('can_finalize')->default(false);
            $table->boolean('can_appraise')->default(false);
            $table->unsignedInteger('max_active_cases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->string('certification_no', 60)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id'], 'sup_supervisor_employee_unique');
            $table->index(['kind', 'is_active'], 'sup_supervisor_kind_idx');
        });

        // ---------- Pengaturan modul ----------
        Schema::create('sup_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 80);
            $table->text('value')->nullable();
            $table->string('cast', 20)->default('string');          // string | int | float | bool | json
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'key'], 'sup_setting_unique');
        });

        // ---------- Audit ----------
        Schema::create('sup_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 60);                           // instrument.published, supervisor.created, ...
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 40)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id'], 'sup_audit_entity_idx');
            $table->index(['action', 'created_at'], 'sup_audit_action_idx');
            $table->index(['actor_id', 'created_at'], 'sup_audit_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sup_audit_logs');
        Schema::dropIfExists('sup_settings');
        Schema::dropIfExists('sup_supervisors');
        Schema::dropIfExists('sup_indicator_options');
        Schema::dropIfExists('sup_indicators');
        Schema::dropIfExists('sup_sections');
        Schema::dropIfExists('sup_instrument_versions');
        Schema::dropIfExists('sup_instruments');
        Schema::dropIfExists('sup_grade_scales');
    }
};
