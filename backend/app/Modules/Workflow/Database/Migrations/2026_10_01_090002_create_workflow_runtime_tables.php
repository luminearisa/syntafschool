<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow & Approval Engine — tabel **runtime** (instance side).
 *
 * Semua tabel di sini bersifat **append-only** kecuali baris yang memang harus
 * berubah status (instance, step instance, penugasan approval, SLA). Keputusan,
 * submission, konteks, event, komentar, dan audit tidak memiliki `updated_at`:
 * riwayat tidak boleh ditulis ulang (§47, §48, §64).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Instance workflow (§16) ----------
        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();           // WF-2026-000123
            $table->foreignId('definition_id')->constrained('workflow_definitions')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('workflow_versions')->restrictOnDelete();
            $table->string('module', 60);
            $table->string('subject_type', 120);
            $table->string('subject_id', 64);
            $table->string('subject_reference', 120)->nullable();
            $table->string('title', 200);
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('requester_department', 80)->nullable();
            $table->string('priority', 20)->default('NORMAL');
            $table->string('status', 30)->default('DRAFT');
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->json('current_node_keys')->nullable();
            $table->json('execution_state')->nullable();
            $table->json('context_summary')->nullable();
            $table->unsignedTinyInteger('submission_number')->default(0);
            $table->unsignedSmallInteger('total_steps')->default(0);
            $table->unsignedSmallInteger('completed_steps')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_decision_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->string('failure_reason', 300)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['requester_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['definition_id', 'status']);
            $table->index(['module', 'status']);
            $table->index('priority');
        });

        // ---------- Step instance (§18, §46) ----------
        Schema::create('workflow_step_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('node_key', 80);
            $table->string('node_type', 20);
            $table->string('name', 150);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedTinyInteger('round')->default(1);
            $table->string('status', 30)->default('PENDING');
            $table->string('approval_mode', 20)->nullable();
            $table->string('quorum_policy', 20)->nullable();
            $table->unsignedSmallInteger('quorum_value')->nullable();
            $table->string('result', 30)->nullable();
            $table->string('branch_token', 80)->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('total_approvers')->default(0);
            $table->unsignedSmallInteger('approvals_count')->default(0);
            $table->unsignedSmallInteger('rejections_count')->default(0);
            $table->unsignedSmallInteger('returns_count')->default(0);
            $table->unsignedInteger('sla_minutes')->nullable();
            $table->string('sla_status', 20)->default('ON_TIME');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'node_key', 'round']);
            $table->index(['instance_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        // ---------- Penugasan approver (§5, §9) ----------
        Schema::create('workflow_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_instance_id')->constrained('workflow_step_instances')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('PENDING');
            $table->string('source', 20);                   // ApproverSource
            $table->string('resolver', 80)->nullable();
            $table->json('resolver_spec')->nullable();
            $table->boolean('is_delegate')->default(false);
            $table->foreignId('original_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('delegation_id')->nullable()->index();
            $table->unsignedBigInteger('escalation_id')->nullable()->index();
            $table->unsignedBigInteger('decision_id')->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['step_instance_id', 'approver_id']);
            $table->index(['approver_id', 'status']);
            $table->index(['instance_id', 'status']);
            $table->index(['due_at', 'status']);
        });

        // ---------- Keputusan (§32, §48) — immutable ----------
        Schema::create('workflow_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_instance_id')->constrained('workflow_step_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('approval_id')->nullable()->index();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('original_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 30);                 // DecisionType
            $table->text('comment')->nullable();
            $table->boolean('is_override')->default(false);
            $table->string('override_reason', 300)->nullable();
            $table->unsignedBigInteger('delegation_id')->nullable()->index();
            $table->unsignedBigInteger('escalation_id')->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['instance_id', 'decided_at']);
            $table->index(['step_instance_id', 'decision']);
        });

        // ---------- Riwayat submission (§35) ----------
        Schema::create('workflow_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedTinyInteger('submission_number');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('revision_reason', 300)->nullable();
            $table->string('context_hash', 64)->nullable();
            $table->string('status', 20)->default('SUBMITTED');
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['instance_id', 'submission_number']);
        });

        // ---------- Snapshot konteks (§65) ----------
        Schema::create('workflow_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedTinyInteger('submission_number')->default(1);
            $table->string('provider', 150);
            $table->json('snapshot');
            $table->string('hash', 64);
            $table->timestamp('resolved_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['instance_id', 'submission_number']);
        });

        // ---------- Event (§44, §47) ----------
        Schema::create('workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('definition_id')->nullable()->index();
            $table->unsignedBigInteger('step_instance_id')->nullable()->index();
            $table->string('type', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['instance_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        // ---------- Diskusi & lampiran (§66) ----------
        Schema::create('workflow_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('step_instance_id')->nullable()->index();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('workflow_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('step_instance_id')->nullable()->index();
            $table->string('label', 150)->nullable();
            $table->string('file_path', 300)->nullable();
            $table->string('original_name', 200)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('source', 30)->default('WORKFLOW'); // WORKFLOW|DOCUMENT_PROVIDER|EXTERNAL
            $table->string('external_ref', 200)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // ---------- SLA (§41, §42) ----------
        Schema::create('workflow_sla_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_instance_id')->constrained('workflow_step_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('calendar_id')->nullable()->index();
            $table->unsignedInteger('sla_minutes');
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('due_soon_at')->nullable();
            $table->timestamp('escalate_at')->nullable();
            $table->string('status', 20)->default('ON_TIME');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedTinyInteger('escalation_count')->default(0);
            $table->unsignedTinyInteger('max_escalations')->default(3);
            $table->json('escalated_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('step_instance_id');
            $table->index(['status', 'due_at']);
        });

        // ---------- Eskalasi (§40) ----------
        Schema::create('workflow_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_instance_id')->constrained('workflow_step_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('sla_record_id')->nullable()->index();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('action', 20);                   // EscalationAction
            $table->string('reason', 300)->nullable();
            $table->unsignedBigInteger('from_approver_id')->nullable()->index();
            $table->unsignedBigInteger('to_user_id')->nullable()->index();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('applied_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['instance_id', 'level']);
        });

        // ---------- Notifikasi (§67, §68) ----------
        Schema::create('workflow_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedBigInteger('step_instance_id')->nullable()->index();
            $table->string('event', 40);
            $table->string('channel', 20);
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_address', 160)->nullable();
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->string('status', 20)->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status']);
            $table->index(['event', 'created_at']);
        });

        // ---------- Audit berantai (§48) ----------
        Schema::create('workflow_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
            $table->unsignedBigInteger('definition_id')->nullable()->index();
            $table->unsignedBigInteger('version_id')->nullable()->index();
            $table->unsignedBigInteger('step_instance_id')->nullable()->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('target_type', 150)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 300)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('hash', 64);
            $table->string('previous_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['instance_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_audit_logs');
        Schema::dropIfExists('workflow_notifications');
        Schema::dropIfExists('workflow_escalations');
        Schema::dropIfExists('workflow_sla_records');
        Schema::dropIfExists('workflow_attachments');
        Schema::dropIfExists('workflow_comments');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_contexts');
        Schema::dropIfExists('workflow_submissions');
        Schema::dropIfExists('workflow_decisions');
        Schema::dropIfExists('workflow_approvals');
        Schema::dropIfExists('workflow_step_instances');
        Schema::dropIfExists('workflow_instances');
    }
};
