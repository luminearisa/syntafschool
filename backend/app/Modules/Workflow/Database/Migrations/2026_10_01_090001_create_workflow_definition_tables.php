<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow & Approval Engine — tabel **desain** (definition side).
 *
 * Berisi definisi, versi, graf (node/edge), pustaka kondisi & aksi, serta
 * kalender kerja. Semuanya bersifat konfigurasi: tidak menyimpan dokumen
 * bisnis apa pun, sehingga modul ERP mana pun dapat memakainya tanpa
 * ketergantungan data.
 *
 * Kolom berjenis status memakai `string` (bukan `enum` basis data) dan nilainya
 * diikat oleh enum PHP di `App\Modules\Workflow\Domain\Enums`, sehingga
 * penambahan jenis/status baru tidak membutuhkan `ALTER TABLE`.
 *
 * Catatan pemasangan: seluruh tabel berprefix `workflow_` dan tidak mengubah
 * tabel inti ERP — aman dijalankan pada basis data yang sudah berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Definisi workflow (§13) ----------
        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();          // PURCHASE_REQUEST_APPROVAL
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('module', 60);                   // finance | procurement | hrd | ...
            $table->string('event', 100)->nullable();       // PurchaseRequestSubmitted
            $table->string('entity_type', 120)->nullable(); // subject_type, mis. finance.purchase_request
            $table->string('category', 60)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->string('trigger_type', 20)->default('MANUAL');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_department', 80)->nullable();
            // Kolom tanpa FK: rujukan melingkar dengan workflow_versions.
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->boolean('allow_self_approval')->default(false);
            $table->json('comment_required_on')->nullable();
            $table->json('required_context')->nullable();
            $table->json('observers')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['module', 'status']);
            $table->index(['entity_type', 'status']);
            $table->index(['event', 'status']);
        });

        // ---------- Versi workflow (§14) ----------
        Schema::create('workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('DRAFT');
            $table->string('label', 150)->nullable();
            $table->text('changelog')->nullable();
            $table->json('config')->nullable();             // SLA default, kebijakan, kanal notifikasi
            $table->json('compiled_graph')->nullable();     // salinan beku nodes+edges saat publish
            $table->string('graph_hash', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['definition_id', 'version']);
            $table->index(['definition_id', 'status']);
        });

        // ---------- Node graf (§18, §54) ----------
        Schema::create('workflow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('type', 20);                     // NodeType
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('approval_mode', 20)->nullable();  // ApprovalMode
            $table->string('quorum_policy', 20)->nullable();  // QuorumPolicy
            $table->unsignedSmallInteger('quorum_value')->nullable();
            $table->unsignedInteger('sla_minutes')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('allow_skip')->default(false);
            $table->boolean('allow_reassign')->default(true);
            $table->string('self_approval_rule', 20)->default('INHERIT'); // INHERIT|FORBID|ALLOW
            $table->json('approvers')->nullable();          // daftar spesifikasi resolver
            $table->json('tiers')->nullable();              // untuk mode HYBRID
            $table->string('condition_code', 80)->nullable();
            $table->json('condition')->nullable();          // kondisi aktivasi node (inline)
            $table->json('escalation')->nullable();
            $table->json('actions')->nullable();            // aksi otomatis per hook
            $table->json('notification')->nullable();
            $table->json('config')->nullable();
            $table->smallInteger('position_x')->nullable();
            $table->smallInteger('position_y')->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'key']);
            $table->index(['version_id', 'type']);
        });

        // ---------- Edge graf (§54, §55) ----------
        Schema::create('workflow_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('from_key', 80);
            $table->string('to_key', 80);
            $table->string('label', 150)->nullable();
            $table->string('condition_code', 80)->nullable();
            $table->json('condition')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['version_id', 'from_key', 'to_key']);
            $table->index(['version_id', 'from_key']);
            $table->index(['version_id', 'to_key']);
        });

        // ---------- Pustaka kondisi bernama (§30) ----------
        Schema::create('workflow_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name', 150);
            $table->string('logic', 10)->default('AND');    // AND|OR|NOT
            $table->json('expression')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'code']);
        });

        // ---------- Pustaka aksi bernama (§43) ----------
        Schema::create('workflow_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name', 150)->nullable();
            $table->string('type', 40);                     // CREATE_BUSINESS|UPDATE_STATUS|NOTIFY|EVENT|CUSTOM
            $table->string('handler', 150)->nullable();     // alias handler yang terdaftar
            $table->json('params')->nullable();
            $table->string('on_failure', 20)->default('LOG'); // LOG|FAIL
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version_id', 'key']);
        });

        // ---------- Kalender bisnis (§70) ----------
        Schema::create('workflow_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->string('timezone', 60)->default('Asia/Jakarta');
            $table->json('work_days')->nullable();          // [1,2,3,4,5] ISO-8601
            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_calendar_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('workflow_calendars')->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 150);
            $table->boolean('is_working_day')->default(false); // pengganti hari libur (mis. cuti bersama kerja)
            $table->timestamps();

            $table->unique(['calendar_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_calendar_holidays');
        Schema::dropIfExists('workflow_calendars');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_conditions');
        Schema::dropIfExists('workflow_edges');
        Schema::dropIfExists('workflow_nodes');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
    }
};
