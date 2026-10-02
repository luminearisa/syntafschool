<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('hr_workforce_plans', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('planning_horizon')->nullable();
            $table->string('plan_type')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('owner_id')->nullable()->index();
            $table->integer('version')->default(1);
            $table->json('assumptions')->nullable();
            $table->string('strategy_reference')->nullable();
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->string('currency')->nullable();
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_plan_versions', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->integer('version')->default(1);
            $table->string('status')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('demand_summary')->nullable();
            $table->json('supply_summary')->nullable();
            $table->json('gap_summary')->nullable();
            $table->decimal('cost_forecast', 18, 2)->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->dateTime('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_demand', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->integer('version')->default(1);
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->foreignId('job_id')->nullable()->index();
            $table->foreignId('location_id')->nullable()->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->integer('required_headcount')->default(0);
            $table->json('required_skills')->nullable();
            $table->decimal('required_capacity', 18, 2)->nullable();
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->string('driver')->nullable();
            $table->string('driver_reference')->nullable();
            $table->string('priority')->nullable();
            $table->string('status')->nullable();
            $table->json('assumptions')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_supply', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->integer('version')->default(1);
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->foreignId('location_id')->nullable()->index();
            $table->date('as_of')->nullable();
            $table->integer('active_headcount')->default(0);
            $table->integer('open_positions')->default(0);
            $table->integer('expected_retirements')->default(0);
            $table->integer('contract_expiries')->default(0);
            $table->decimal('expected_attrition', 18, 2)->nullable();
            $table->integer('internal_mobility')->default(0);
            $table->json('available_skills')->nullable();
            $table->integer('talent_pool_size')->default(0);
            $table->decimal('capacity_hours', 18, 2)->nullable();
            $table->decimal('cost_amount', 18, 2)->nullable();
            $table->json('breakdown')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_gaps', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->integer('version')->default(1);
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->string('gap_type')->nullable();
            $table->string('dimension_key')->nullable();
            $table->decimal('required_value', 18, 2)->nullable();
            $table->decimal('available_value', 18, 2)->nullable();
            $table->decimal('gap_value', 18, 2)->nullable();
            $table->string('severity')->nullable();
            $table->json('recommended_actions')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_scenarios', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('scenario_type')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('inputs')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('baseline_scenario_id')->nullable();
            $table->foreignId('created_by')->nullable()->index();
            $table->dateTime('simulated_at')->nullable();
            $table->json('results')->nullable();
            $table->json('comparison')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_scenario_assumptions', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('scenario_id')->nullable()->index();
            $table->string('key')->index()->nullable();
            $table->string('label')->nullable();
            $table->string('value_type')->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->json('value_json')->nullable();
            $table->string('source')->nullable();
            $table->boolean('is_driver')->default(false);
            $table->json('evidence')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_forecasts', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->foreignId('scenario_id')->nullable()->index();
            $table->string('forecast_model_code')->nullable();
            $table->string('forecast_type')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('period')->index()->nullable();
            $table->integer('horizon_months')->default(0);
            $table->decimal('value', 18, 2)->nullable();
            $table->decimal('lower_bound', 18, 2)->nullable();
            $table->decimal('upper_bound', 18, 2)->nullable();
            $table->decimal('confidence', 18, 2)->nullable();
            $table->string('method')->nullable();
            $table->json('inputs')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_cost_forecasts', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->foreignId('scenario_id')->nullable()->index();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('period')->index()->nullable();
            $table->decimal('salary_amount', 18, 2)->nullable();
            $table->decimal('allowance_amount', 18, 2)->nullable();
            $table->decimal('benefit_amount', 18, 2)->nullable();
            $table->decimal('overtime_amount', 18, 2)->nullable();
            $table->decimal('recruitment_amount', 18, 2)->nullable();
            $table->decimal('training_amount', 18, 2)->nullable();
            $table->decimal('severance_reference_amount', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->decimal('variance_amount', 18, 2)->nullable();
            $table->integer('headcount')->default(0);
            $table->json('assumptions')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_organization_design_scenarios', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('base_organization_id')->nullable()->index();
            $table->json('proposal')->nullable();
            $table->string('status')->nullable();
            $table->decimal('impact_headcount', 18, 2)->nullable();
            $table->decimal('impact_cost', 18, 2)->nullable();
            $table->integer('impact_layers')->default(0);
            $table->decimal('impact_span_of_control', 18, 2)->nullable();
            $table->foreignId('proposed_by')->nullable()->index();
            $table->dateTime('simulated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_position_plans', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('action')->nullable();
            $table->foreignId('from_organization_id')->nullable()->index();
            $table->foreignId('to_organization_id')->nullable()->index();
            $table->string('from_grade_code')->nullable();
            $table->string('to_grade_code')->nullable();
            $table->integer('headcount_delta')->default(0);
            $table->decimal('cost_delta', 18, 2)->nullable();
            $table->date('effective_date')->nullable();
            $table->foreignId('target_incumbent_employee_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_workforce_resource_requirements', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('plan_id')->nullable()->index();
            $table->foreignId('scenario_id')->nullable()->index();
            $table->string('resource_type')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 18, 2)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->date('needed_on')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
Schema::dropIfExists('hr_workforce_plans');

Schema::dropIfExists('hr_workforce_plan_versions');

Schema::dropIfExists('hr_workforce_demand');

Schema::dropIfExists('hr_workforce_supply');

Schema::dropIfExists('hr_workforce_gaps');

Schema::dropIfExists('hr_workforce_scenarios');

Schema::dropIfExists('hr_workforce_scenario_assumptions');

Schema::dropIfExists('hr_workforce_forecasts');

Schema::dropIfExists('hr_workforce_cost_forecasts');

Schema::dropIfExists('hr_organization_design_scenarios');

Schema::dropIfExists('hr_position_plans');

Schema::dropIfExists('hr_workforce_resource_requirements');

    }
};
