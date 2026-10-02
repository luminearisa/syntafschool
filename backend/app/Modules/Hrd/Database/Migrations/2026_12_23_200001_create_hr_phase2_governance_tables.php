<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->string('purpose', 191);
            $table->text('description')->nullable();
            $table->string('match_mode', 191)->default('ALL');
            $table->integer('priority')->default(0);
            $table->string('action_type', 191);
            $table->json('action_payload')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['purpose', 'is_active']);
        });

        Schema::create('hr_rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('hr_rules')->cascadeOnDelete();
            $table->string('field', 191);
            $table->string('operator', 191);
            $table->json('value')->nullable();
            $table->integer('sequence')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_rule_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('hr_rules')->nullOnDelete();
            $table->string('purpose', 191);
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->boolean('matched')->default(false);
            $table->json('trace')->nullable();
            $table->dateTime('evaluated_at')->useCurrent();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['purpose', 'employee_id']);
        });

        Schema::create('hr_workforce_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('event_type', 191);
            $table->string('event_category', 191);
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->dateTime('occurred_at')->useCurrent();
            $table->string('title', 191)->nullable();
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('visible_to_employee')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['event_category', 'event_type']);
        });

        Schema::create('hr_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('automation', 191);
            $table->string('run_key', 191)->unique();
            $table->string('status', 191)->default('RUNNING');
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('finished_at')->nullable();
            $table->integer('processed')->default(0);
            $table->integer('failed')->default(0);
            $table->json('summary')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 191)->unique();
            $table->string('alert_type', 191);
            $table->string('severity', 191)->default('INFO');
            $table->string('title', 191);
            $table->text('message')->nullable();
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 191)->default('OPEN');
            $table->json('payload')->nullable();
            $table->dateTime('triggered_at')->useCurrent();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('hr_service_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('category', 191);
            $table->string('subject', 191);
            $table->text('description')->nullable();
            $table->string('priority', 191)->default('NORMAL');
            $table->string('status', 191)->default('OPEN');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sla_due_at')->nullable();
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->string('helpdesk_reference', 191)->nullable();
            $table->integer('satisfaction_score')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'priority']);
            $table->index(['category', 'status']);
        });

        Schema::create('hr_service_ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('hr_service_tickets')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->string('attachment_path', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('capability', 191);
            $table->string('provider', 191);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('status', 191)->default('PENDING');
            $table->string('prompt_hash', 191)->nullable();
            $table->json('redacted_payload')->nullable();
            $table->json('response')->nullable();
            $table->boolean('human_reviewed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->integer('tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['capability', 'status']);
        });

        Schema::create('hr_analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('scope', 191);
            $table->foreignId('organization_id')->nullable()->constrained('hr_organizations')->nullOnDelete();
            $table->json('metrics')->nullable();
            $table->integer('headcount')->default(0);
            $table->decimal('cost_total', 15,2)->nullable();
            $table->timestamps();
            $table->unique(['snapshot_date', 'scope', 'organization_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('hr_analytics_snapshots');
        Schema::dropIfExists('hr_ai_requests');
        Schema::dropIfExists('hr_service_ticket_comments');
        Schema::dropIfExists('hr_service_tickets');
        Schema::dropIfExists('hr_alerts');
        Schema::dropIfExists('hr_automation_runs');
        Schema::dropIfExists('hr_workforce_events');
        Schema::dropIfExists('hr_rule_evaluations');
        Schema::dropIfExists('hr_rule_conditions');
        Schema::dropIfExists('hr_rules');
    }
};
