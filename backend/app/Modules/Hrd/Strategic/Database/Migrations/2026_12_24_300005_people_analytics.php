<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_metric_definitions', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('entity')->nullable();
            $table->string('formula_type')->nullable();
            $table->json('numerator')->nullable();
            $table->json('denominator')->nullable();
            $table->json('filters')->nullable();
            $table->json('dimensions')->nullable();
            $table->string('period_basis')->nullable();
            $table->string('unit')->nullable();
            $table->text('description')->nullable();
            $table->string('owner_role')->nullable();
            $table->string('steward_role')->nullable();
            $table->string('data_classification')->nullable();
            $table->string('refresh_frequency')->nullable();
            $table->boolean('is_canonical')->default(true);
            $table->json('source_tables')->nullable();
            $table->string('sensitivity')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_metric_dimensions', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('metric_definition_id')->nullable()->index();
            $table->string('dimension')->nullable();
            $table->string('source_field')->nullable();
            $table->json('values')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_metric_snapshots', function (Blueprint $table): void
        {
            $table->id();
            $table->string('metric_code')->index()->nullable();
            $table->string('period')->index()->nullable();
            $table->date('as_of')->nullable();
            $table->string('scope')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->json('dimension_values')->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->decimal('numerator_value', 18, 2)->nullable();
            $table->decimal('denominator_value', 18, 2)->nullable();
            $table->integer('sample_size')->default(0);
            $table->json('trend_ref')->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->string('model_version')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_workforce_snapshots', function (Blueprint $table): void
        {
            $table->id();
            $table->date('snapshot_date')->nullable();
            $table->string('granularity')->nullable();
            $table->string('scope')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->integer('headcount')->default(0);
            $table->integer('active_headcount')->default(0);
            $table->integer('open_positions')->default(0);
            $table->integer('positions')->default(0);
            $table->decimal('avg_tenure_months', 18, 2)->nullable();
            $table->decimal('payroll_cost', 18, 2)->nullable();
            $table->decimal('benefit_cost', 18, 2)->nullable();
            $table->decimal('absence_days', 18, 2)->nullable();
            $table->decimal('absence_rate', 8, 2)->nullable();
            $table->decimal('performance_avg', 8, 2)->nullable();
            $table->decimal('skill_coverage', 8, 2)->nullable();
            $table->json('turnover_signals')->nullable();
            $table->json('breakdown')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_people_analytics_snapshots', function (Blueprint $table): void
        {
            $table->id();
            $table->date('snapshot_date')->nullable();
            $table->string('granularity')->nullable();
            $table->string('scope')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('domain')->nullable();
            $table->json('metrics')->nullable();
            $table->json('insights')->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->string('refresh_policy')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_analytics_dashboards', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('audience')->nullable();
            $table->string('scope_type')->nullable();
            $table->json('default_filters')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->json('layout')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_analytics_widgets', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('dashboard_id')->nullable()->index();
            $table->string('code')->nullable();
            $table->string('title')->nullable();
            $table->string('widget_type')->nullable();
            $table->string('metric_code')->nullable();
            $table->json('dimensions')->nullable();
            $table->json('query_plan')->nullable();
            $table->integer('position')->default(0);
            $table->string('size')->nullable();
            $table->json('filters')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_analytics_saved_views', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('entity')->nullable();
            $table->json('query_plan')->nullable();
            $table->json('filters')->nullable();
            $table->text('natural_language')->nullable();
            $table->boolean('shared')->default(false);
            $table->dateTime('last_run_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_metric_lineage', function (Blueprint $table): void
        {
            $table->id();
            $table->string('metric_code')->index()->nullable();
            $table->string('source_table')->nullable();
            $table->string('source_field')->nullable();
            $table->string('filter_expression')->nullable();
            $table->text('calculation')->nullable();
            $table->string('period_basis')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->index();
            $table->dateTime('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_data_catalog', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('dataset')->nullable();
            $table->string('owner_role')->nullable();
            $table->string('steward_role')->nullable();
            $table->text('definition')->nullable();
            $table->json('source_tables')->nullable();
            $table->string('refresh_frequency')->nullable();
            $table->string('sensitivity')->nullable();
            $table->json('access_roles')->nullable();
            $table->string('retention_policy')->nullable();
            $table->string('classification')->nullable();
            $table->dateTime('last_reviewed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_data_quality_rules', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('dataset')->nullable();
            $table->string('rule_type')->nullable();
            $table->text('expression')->nullable();
            $table->string('severity')->nullable();
            $table->string('owner_role')->nullable();
            $table->string('status')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->json('last_result')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_experiments', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('initiative_type')->nullable();
            $table->json('population')->nullable();
            $table->json('baseline_metrics')->nullable();
            $table->text('intervention')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('owner_employee_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_experiment_results', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('experiment_id')->nullable()->index();
            $table->string('metric_code')->nullable();
            $table->decimal('baseline_value', 18, 2)->nullable();
            $table->decimal('intervention_value', 18, 2)->nullable();
            $table->decimal('delta', 18, 2)->nullable();
            $table->decimal('delta_percent', 8, 2)->nullable();
            $table->integer('cohort_size')->default(0);
            $table->text('significance_note')->nullable();
            $table->json('evidence')->nullable();
            $table->dateTime('measured_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_semantic_entities', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('source_model')->nullable();
            $table->text('description')->nullable();
            $table->json('allowed_filters')->nullable();
            $table->json('allowed_dimensions')->nullable();
            $table->json('allowed_metrics')->nullable();
            $table->string('sensitivity')->nullable();
            $table->string('requires_permission')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_semantic_fields', function (Blueprint $table): void
        {
            $table->id();
            $table->string('entity_code')->index()->nullable();
            $table->string('field')->nullable();
            $table->string('label')->nullable();
            $table->string('data_type')->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_dimension')->default(false);
            $table->boolean('is_metric')->default(false);
            $table->string('sensitivity')->nullable();
            $table->string('permission')->nullable();
            $table->string('source_field')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_metric_definitions');
        Schema::dropIfExists('hr_metric_dimensions');
        Schema::dropIfExists('hr_metric_snapshots');
        Schema::dropIfExists('hr_workforce_snapshots');
        Schema::dropIfExists('hr_people_analytics_snapshots');
        Schema::dropIfExists('hr_analytics_dashboards');
        Schema::dropIfExists('hr_analytics_widgets');
        Schema::dropIfExists('hr_analytics_saved_views');
        Schema::dropIfExists('hr_metric_lineage');
        Schema::dropIfExists('hr_data_catalog');
        Schema::dropIfExists('hr_data_quality_rules');
        Schema::dropIfExists('hr_experiments');
        Schema::dropIfExists('hr_experiment_results');
        Schema::dropIfExists('hr_semantic_entities');
        Schema::dropIfExists('hr_semantic_fields');
    }
};
