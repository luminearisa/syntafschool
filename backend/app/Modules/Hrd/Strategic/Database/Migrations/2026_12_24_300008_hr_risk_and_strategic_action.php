<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_risks', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('title')->nullable();
            $table->string('risk_type')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('scope_organization_id')->nullable()->index();
            $table->string('severity')->nullable();
            $table->string('likelihood')->nullable();
            $table->decimal('impact_score', 8, 2)->nullable();
            $table->decimal('risk_score', 8, 2)->nullable();
            $table->string('period')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->string('source')->nullable();
            $table->dateTime('detected_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_risk_signals', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('risk_id')->nullable()->index();
            $table->string('code')->nullable();
            $table->string('signal_type')->nullable();
            $table->string('severity')->nullable();
            $table->decimal('signal_value', 18, 2)->nullable();
            $table->decimal('threshold', 18, 2)->nullable();
            $table->json('evidence')->nullable();
            $table->string('period')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('employee_id')->nullable()->index();
            $table->foreignId('organization_id')->nullable()->index();
            $table->text('recommended_action')->nullable();
            $table->dateTime('detected_at')->nullable();
            $table->string('status')->nullable();
            $table->string('dedupe_key')->index()->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_risk_evidence', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('risk_id')->nullable()->index();
            $table->foreignId('signal_id')->nullable()->index();
            $table->string('evidence_type')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->string('period')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('collected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_risk_actions', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('risk_id')->nullable()->index();
            $table->string('action_type')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->string('status')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('strategic_action_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_risk_history', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('risk_id')->nullable()->index();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('from_severity')->nullable();
            $table->string('to_severity')->nullable();
            $table->foreignId('changed_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_strategic_hr_actions', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('title')->nullable();
            $table->text('objective')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('linked_risk_id')->nullable()->index();
            $table->foreignId('linked_recommendation_id')->nullable()->index();
            $table->text('workforce_requirement')->nullable();
            $table->text('skill_requirement')->nullable();
            $table->text('talent_strategy')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->index();
            $table->string('owner_role')->nullable();
            $table->foreignId('owner_organization_id')->nullable()->index();
            $table->string('priority')->nullable();
            $table->string('status')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->decimal('actual_cost', 18, 2)->nullable();
            $table->json('measurement')->nullable();
            $table->string('workflow_reference')->nullable();
            $table->string('approval_status')->nullable();
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_strategic_hr_action_items', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('action_id')->nullable()->index();
            $table->integer('sequence')->default(0);
            $table->string('title')->nullable();
            $table->string('item_type')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->string('status')->nullable();
            $table->decimal('progress_percent', 8, 2)->nullable();
            $table->foreignId('depends_on_item_id')->nullable()->index();
            $table->string('workflow_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_strategic_hr_action_results', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('action_id')->nullable()->index();
            $table->foreignId('item_id')->nullable()->index();
            $table->string('metric_code')->nullable();
            $table->decimal('baseline_value', 18, 2)->nullable();
            $table->decimal('target_value', 18, 2)->nullable();
            $table->decimal('actual_value', 18, 2)->nullable();
            $table->decimal('variance', 18, 2)->nullable();
            $table->string('period')->nullable();
            $table->json('evidence')->nullable();
            $table->dateTime('measured_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_strategic_hr_action_reviews', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('action_id')->nullable()->index();
            $table->date('review_date')->nullable();
            $table->foreignId('reviewer_employee_id')->nullable()->index();
            $table->text('progress_note')->nullable();
            $table->json('blockers')->nullable();
            $table->json('decisions')->nullable();
            $table->date('next_review_date')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_risks');
        Schema::dropIfExists('hr_risk_signals');
        Schema::dropIfExists('hr_risk_evidence');
        Schema::dropIfExists('hr_risk_actions');
        Schema::dropIfExists('hr_risk_history');
        Schema::dropIfExists('hr_strategic_hr_actions');
        Schema::dropIfExists('hr_strategic_hr_action_items');
        Schema::dropIfExists('hr_strategic_hr_action_results');
        Schema::dropIfExists('hr_strategic_hr_action_reviews');
    }
};
