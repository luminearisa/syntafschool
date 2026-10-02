<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_forecast_models', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('purpose')->nullable();
            $table->string('method')->nullable();
            $table->integer('version')->default(1);
            $table->json('parameters')->nullable();
            $table->json('features')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('approval_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->json('metrics')->nullable();
            $table->string('owner_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_forecast_runs', function (Blueprint $table): void
        {
            $table->id();
            $table->string('model_code')->index()->nullable();
            $table->string('code')->unique();
            $table->string('purpose')->nullable();
            $table->string('scope')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('period')->index()->nullable();
            $table->integer('horizon_months')->default(0);
            $table->string('status')->nullable();
            $table->json('inputs')->nullable();
            $table->json('outputs')->nullable();
            $table->json('summary')->nullable();
            $table->decimal('confidence', 18, 2)->nullable();
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('idempotency_key')->index()->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_forecast_inputs', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('run_id')->nullable()->index();
            $table->string('input_type')->nullable();
            $table->string('key')->index()->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->json('value_json')->nullable();
            $table->string('period')->nullable();
            $table->string('source')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_forecast_outputs', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('run_id')->nullable()->index();
            $table->string('period')->index()->nullable();
            $table->string('metric_key')->index()->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->decimal('lower_bound', 18, 2)->nullable();
            $table->decimal('upper_bound', 18, 2)->nullable();
            $table->decimal('confidence', 18, 2)->nullable();
            $table->string('method')->nullable();
            $table->json('drivers')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_forecast_scenarios', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('base_model_code')->nullable();
            $table->string('scenario_type')->nullable();
            $table->json('assumptions')->nullable();
            $table->foreignId('link_scenario_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_forecast_assumptions', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('forecast_scenario_id')->nullable()->index();
            $table->string('key')->index()->nullable();
            $table->string('label')->nullable();
            $table->string('value_type')->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->json('value_json')->nullable();
            $table->string('source')->nullable();
            $table->json('evidence')->nullable();
            $table->string('owner_role')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_model_registry', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('model_type')->nullable();
            $table->string('version')->nullable();
            $table->string('purpose')->nullable();
            $table->date('training_period_start')->nullable();
            $table->date('training_period_end')->nullable();
            $table->json('features')->nullable();
            $table->json('metrics')->nullable();
            $table->string('approval_status')->nullable();
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->text('monitoring_notes')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_forecast_models');
        Schema::dropIfExists('hr_forecast_runs');
        Schema::dropIfExists('hr_forecast_inputs');
        Schema::dropIfExists('hr_forecast_outputs');
        Schema::dropIfExists('hr_forecast_scenarios');
        Schema::dropIfExists('hr_forecast_assumptions');
        Schema::dropIfExists('hr_model_registry');
    }
};
