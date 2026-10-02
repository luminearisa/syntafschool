<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_retention_signals', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('signal_level')->nullable();
            $table->decimal('retention_score', 8, 2)->nullable();
            $table->json('factors')->nullable();
            $table->json('contributing_signals')->nullable();
            $table->json('evidence')->nullable();
            $table->string('period')->nullable();
            $table->decimal('tenure_months', 18, 2)->nullable();
            $table->boolean('manager_change_recent')->default(false);
            $table->integer('promotion_gap_months')->default(0);
            $table->decimal('compensation_percentile', 8, 2)->nullable();
            $table->decimal('engagement_score', 8, 2)->nullable();
            $table->integer('attendance_irregularities')->default(0);
            $table->decimal('learning_participation', 8, 2)->nullable();
            $table->text('career_progress_note')->nullable();
            $table->dateTime('detected_at')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_attrition_snapshots', function (Blueprint $table): void
        {
            $table->id();
            $table->string('period')->index()->nullable();
            $table->string('granularity')->nullable();
            $table->string('dimension')->nullable();
            $table->string('dimension_key')->index()->nullable();
            $table->integer('headcount_start')->default(0);
            $table->integer('headcount_end')->default(0);
            $table->integer('leavers')->default(0);
            $table->decimal('turnover_rate', 10, 4)->nullable();
            $table->decimal('avg_tenure_months', 18, 2)->nullable();
            $table->integer('voluntary')->default(0);
            $table->integer('involuntary')->default(0);
            $table->json('trend_ref')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_journey_definitions', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('journey_type')->nullable();
            $table->string('trigger_event')->nullable();
            $table->text('description')->nullable();
            $table->json('target_audience')->nullable();
            $table->string('status')->nullable();
            $table->integer('version')->default(1);
            $table->string('owner_role')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_journey_stages', function (Blueprint $table): void
        {
            $table->id();
            $table->string('journey_code')->index()->nullable();
            $table->integer('sequence')->default(0);
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('stage_type')->nullable();
            $table->integer('offset_days')->default(0);
            $table->string('owner_role')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_journey_instances', function (Blueprint $table): void
        {
            $table->id();
            $table->string('journey_code')->index()->nullable();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('code')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->string('status')->nullable();
            $table->string('current_stage_code')->nullable();
            $table->decimal('progress_percent', 8, 2)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('metrics')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_journey_events', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('instance_id')->nullable()->index();
            $table->string('stage_code')->nullable();
            $table->string('event_type')->nullable();
            $table->foreignId('employee_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->string('handled_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_experience_snapshots', function (Blueprint $table): void
        {
            $table->id();
            $table->date('snapshot_date')->nullable();
            $table->string('granularity')->nullable();
            $table->string('scope')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->decimal('engagement_score', 8, 2)->nullable();
            $table->decimal('participation_rate', 8, 2)->nullable();
            $table->decimal('satisfaction_score', 8, 2)->nullable();
            $table->decimal('manager_effectiveness', 8, 2)->nullable();
            $table->decimal('career_confidence', 8, 2)->nullable();
            $table->decimal('learning_satisfaction', 8, 2)->nullable();
            $table->decimal('hr_service_satisfaction', 8, 2)->nullable();
            $table->integer('service_volume')->default(0);
            $table->decimal('avg_resolution_hours', 18, 2)->nullable();
            $table->decimal('reopen_rate', 8, 2)->nullable();
            $table->integer('recognition_count')->default(0);
            $table->integer('respondents')->default(0);
            $table->boolean('meets_privacy_threshold')->default(false);
            $table->json('breakdown')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_engagement_snapshots', function (Blueprint $table): void
        {
            $table->id();
            $table->string('period')->index()->nullable();
            $table->string('scope')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('metric')->nullable();
            $table->decimal('value', 8, 2)->nullable();
            $table->integer('respondents')->default(0);
            $table->integer('privacy_threshold')->default(5);
            $table->string('source')->nullable();
            $table->json('trend_ref')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_pay_equity_analyses', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->foreignId('scope_organization_id')->nullable()->index();
            $table->json('comparison_group')->nullable();
            $table->string('period')->nullable();
            $table->string('method')->nullable();
            $table->string('status')->nullable();
            $table->json('findings')->nullable();
            $table->integer('anomaly_count')->default(0);
            $table->json('result_summary')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_pay_equity_findings', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('analysis_id')->nullable()->index();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('anomaly_type')->nullable();
            $table->foreignId('position_id')->nullable()->index();
            $table->string('grade_code')->nullable();
            $table->text('comparison_group')->nullable();
            $table->decimal('expected_min', 18, 2)->nullable();
            $table->decimal('expected_max', 18, 2)->nullable();
            $table->decimal('actual_amount', 18, 2)->nullable();
            $table->decimal('variance_percent', 8, 2)->nullable();
            $table->json('evidence')->nullable();
            $table->string('status')->nullable();
            $table->text('explanation')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_compensation_scenarios', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('scenario_type')->nullable();
            $table->decimal('increase_percent', 8, 2)->nullable();
            $table->string('distribution_method')->nullable();
            $table->json('scope')->nullable();
            $table->integer('population_count')->default(0);
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->decimal('projected_total_cost', 18, 2)->nullable();
            $table->decimal('budget_variance', 18, 2)->nullable();
            $table->json('by_department')->nullable();
            $table->json('employee_impact')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('simulated_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_incentive_simulations', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('period')->nullable();
            $table->json('basis')->nullable();
            $table->integer('eligible_count')->default(0);
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->decimal('budget_variance', 18, 2)->nullable();
            $table->json('distribution')->nullable();
            $table->json('top_recipients')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->dateTime('simulated_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_service_insights', function (Blueprint $table): void
        {
            $table->id();
            $table->string('period')->index()->nullable();
            $table->string('category')->nullable();
            $table->integer('volume')->default(0);
            $table->decimal('avg_resolution_hours', 18, 2)->nullable();
            $table->decimal('sla_met_rate', 8, 2)->nullable();
            $table->decimal('reopen_rate', 8, 2)->nullable();
            $table->decimal('satisfaction_avg', 8, 2)->nullable();
            $table->string('recurring_issue_signal')->nullable();
            $table->string('ai_detected_theme')->nullable();
            $table->json('evidence')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_org_relationships', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('from_employee_id')->nullable()->index();
            $table->foreignId('to_employee_id')->nullable()->index();
            $table->string('relationship_type')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('strength', 8, 2)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_retention_signals');
        Schema::dropIfExists('hr_attrition_snapshots');
        Schema::dropIfExists('hr_journey_definitions');
        Schema::dropIfExists('hr_journey_stages');
        Schema::dropIfExists('hr_journey_instances');
        Schema::dropIfExists('hr_journey_events');
        Schema::dropIfExists('hr_experience_snapshots');
        Schema::dropIfExists('hr_engagement_snapshots');
        Schema::dropIfExists('hr_pay_equity_analyses');
        Schema::dropIfExists('hr_pay_equity_findings');
        Schema::dropIfExists('hr_compensation_scenarios');
        Schema::dropIfExists('hr_incentive_simulations');
        Schema::dropIfExists('hr_service_insights');
        Schema::dropIfExists('hr_org_relationships');
    }
};
