<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('hr_critical_positions', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('position_id')->nullable()->index();
            $table->string('criticality')->nullable();
            $table->text('business_impact')->nullable();
            $table->string('replacement_difficulty')->nullable();
            $table->boolean('succession_required')->default(true);
            $table->integer('min_successors')->default(1);
            $table->foreignId('incumbent_employee_id')->nullable()->index();
            $table->string('retirement_risk')->nullable();
            $table->string('turnover_risk')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('flagged_by')->nullable()->index();
            $table->dateTime('flagged_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_succession_plans', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('critical_position_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('plan_type')->nullable();
            $table->string('status')->nullable();
            $table->string('coverage_status')->nullable();
            $table->integer('horizon_months')->default(0);
            $table->foreignId('owner_employee_id')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_succession_candidates', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('candidate_type')->nullable();
            $table->string('readiness')->nullable();
            $table->decimal('readiness_score', 8, 2)->nullable();
            $table->json('skill_gap_snapshot')->nullable();
            $table->json('evidence')->nullable();
            $table->json('development_plan')->nullable();
            $table->string('impact_of_loss')->nullable();
            $table->string('risk_of_loss')->nullable();
            $table->integer('rank')->default(0);
            $table->string('status')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_succession_readiness', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('candidate_id')->nullable()->index();
            $table->foreignId('plan_id')->nullable()->index();
            $table->foreignId('employee_id')->nullable()->index();
            $table->decimal('skills_score', 8, 2)->nullable();
            $table->decimal('performance_score', 8, 2)->nullable();
            $table->decimal('competency_score', 8, 2)->nullable();
            $table->decimal('experience_score', 8, 2)->nullable();
            $table->decimal('aspiration_score', 8, 2)->nullable();
            $table->decimal('development_score', 8, 2)->nullable();
            $table->decimal('certification_score', 8, 2)->nullable();
            $table->decimal('leadership_score', 8, 2)->nullable();
            $table->decimal('readiness_score', 8, 2)->nullable();
            $table->string('readiness_level')->nullable();
            $table->json('evidence')->nullable();
            $table->string('methodology')->nullable();
            $table->dateTime('calculated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_succession_risks', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('critical_position_id')->nullable()->index();
            $table->foreignId('plan_id')->nullable()->index();
            $table->string('risk_type')->nullable();
            $table->string('severity')->nullable();
            $table->integer('successor_count')->default(0);
            $table->json('readiness_summary')->nullable();
            $table->json('evidence')->nullable();
            $table->text('recommended_action')->nullable();
            $table->dateTime('detected_at')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_succession_scenarios', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('critical_position_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('departure_type')->nullable();
            $table->json('results')->nullable();
            $table->foreignId('best_candidate_employee_id')->nullable()->index();
            $table->json('gaps')->nullable();
            $table->json('development_required')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->dateTime('simulated_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_leadership_pipeline', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('stage')->nullable();
            $table->date('stage_since')->nullable();
            $table->string('source')->nullable();
            $table->json('development_actions')->nullable();
            $table->foreignId('sponsor_employee_id')->nullable()->index();
            $table->string('readiness_for_next')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
Schema::dropIfExists('hr_critical_positions');

Schema::dropIfExists('hr_succession_plans');

Schema::dropIfExists('hr_succession_candidates');

Schema::dropIfExists('hr_succession_readiness');

Schema::dropIfExists('hr_succession_risks');

Schema::dropIfExists('hr_succession_scenarios');

Schema::dropIfExists('hr_leadership_pipeline');

    }
};
