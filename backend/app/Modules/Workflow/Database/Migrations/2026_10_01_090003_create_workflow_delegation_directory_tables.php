<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow & Approval Engine — delegasi & direktori organisasi.
 *
 * `workflow_delegations` adalah pemindahan kewenangan yang **terbatas waktu dan
 * bercakupan** (§8, §39). `workflow_org_reports`/`workflow_org_assignments`
 * adalah direktori minimal agar resolver `manager`, `department`, `position`,
 * dan `cost_center` dapat bekerja tanpa engine menebak struktur organisasi
 * sekolah (§25–§27). Keduanya dapat digantikan implementasi lain lewat
 * `OrganizationalDirectoryInterface`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->json('scope')->nullable();              // definisi/modul/node/rentang nominal
            $table->string('reason', 300)->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('status', 20)->default('PENDING'); // DelegationStatus
            $table->boolean('notify_delegator')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delegator_id', 'status']);
            $table->index(['delegate_id', 'status']);
            $table->index(['end_at', 'status']);
        });

        Schema::create('workflow_org_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('backup_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_org_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 30);               // OrgScopeType
            $table->string('scope_key', 120);               // nama unit, jabatan, id cost center, kode kampus
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->smallInteger('priority')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_key', 'user_id']);
            $table->index(['scope_type', 'scope_key', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_org_assignments');
        Schema::dropIfExists('workflow_org_reports');
        Schema::dropIfExists('workflow_delegations');
    }
};
