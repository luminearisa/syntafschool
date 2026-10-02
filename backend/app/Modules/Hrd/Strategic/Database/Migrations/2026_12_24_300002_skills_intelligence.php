<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('hr_skill_domains', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_categories', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->foreignId('domain_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_relationships', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('skill_id')->nullable()->index();
            $table->foreignId('related_skill_id')->nullable()->index();
            $table->string('relationship_type')->nullable();
            $table->decimal('strength', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_levels', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->integer('ordinal')->default(0);
            $table->text('description')->nullable();
            $table->json('evidence_expectation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_requirements', function (Blueprint $table): void
        {
            $table->id();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('requirement_type')->nullable();
            $table->string('required_level')->nullable();
            $table->integer('required_level_ordinal')->default(0);
            $table->decimal('weight', 8, 2)->nullable();
            $table->text('rationale')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_position_skills', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('position_id')->nullable()->index();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('requirement_type')->nullable();
            $table->string('required_level')->nullable();
            $table->integer('required_ordinal')->default(0);
            $table->boolean('is_critical')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_job_skills', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('job_id')->nullable()->index();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('requirement_type')->nullable();
            $table->string('required_level')->nullable();
            $table->integer('required_ordinal')->default(0);
            $table->boolean('is_critical')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_employee_skill_profiles', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->integer('verified_skill_count')->default(0);
            $table->integer('evidence_count')->default(0);
            $table->decimal('avg_confidence', 8, 2)->nullable();
            $table->decimal('critical_skill_coverage', 8, 2)->nullable();
            $table->json('top_skills')->nullable();
            $table->integer('stale_skill_count')->default(0);
            $table->integer('emerging_skill_count')->default(0);
            $table->dateTime('last_refreshed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_evidence', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('evidence_type')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('level')->nullable();
            $table->integer('level_ordinal')->default(0);
            $table->decimal('confidence', 8, 2)->nullable();
            $table->foreignId('verified_by')->nullable()->index();
            $table->dateTime('verified_at')->nullable();
            $table->date('observed_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->json('details')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_assessments', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('method')->nullable();
            $table->string('assessed_level')->nullable();
            $table->integer('assessed_ordinal')->default(0);
            $table->integer('evidence_count')->default(0);
            $table->foreignId('assessor_employee_id')->nullable()->index();
            $table->dateTime('assessed_at')->nullable();
            $table->decimal('confidence', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_gaps', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('current_level')->nullable();
            $table->integer('current_ordinal')->default(0);
            $table->string('required_level')->nullable();
            $table->integer('required_ordinal')->default(0);
            $table->integer('gap')->default(0);
            $table->string('priority')->nullable();
            $table->json('development_options')->nullable();
            $table->decimal('estimated_cost', 18, 2)->nullable();
            $table->string('status')->nullable();
            $table->dateTime('detected_at')->nullable();
            $table->foreignId('plan_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_demand', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('period')->index()->nullable();
            $table->string('demand_type')->nullable();
            $table->integer('required_headcount')->default(0);
            $table->decimal('required_capacity', 18, 2)->nullable();
            $table->string('required_level')->nullable();
            $table->string('criticality')->nullable();
            $table->string('source')->nullable();
            $table->json('evidence')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_forecasts', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('skill_domain')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('period')->index()->nullable();
            $table->decimal('current_supply', 18, 2)->nullable();
            $table->decimal('future_demand', 18, 2)->nullable();
            $table->decimal('gap', 18, 2)->nullable();
            $table->decimal('development_capacity', 18, 2)->nullable();
            $table->decimal('hiring_alternative', 18, 2)->nullable();
            $table->decimal('scarcity_index', 8, 2)->nullable();
            $table->string('method')->nullable();
            $table->json('assumptions')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_skill_governance', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('skill_id')->nullable()->index();
            $table->string('skill_code')->index()->nullable();
            $table->string('policy')->nullable();
            $table->string('status')->nullable();
            $table->text('issue')->nullable();
            $table->foreignId('steward_employee_id')->nullable()->index();
            $table->dateTime('last_reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
Schema::dropIfExists('hr_skill_domains');

Schema::dropIfExists('hr_skill_categories');

Schema::dropIfExists('hr_skill_relationships');

Schema::dropIfExists('hr_skill_levels');

Schema::dropIfExists('hr_skill_requirements');

Schema::dropIfExists('hr_position_skills');

Schema::dropIfExists('hr_job_skills');

Schema::dropIfExists('hr_employee_skill_profiles');

Schema::dropIfExists('hr_skill_evidence');

Schema::dropIfExists('hr_skill_assessments');

Schema::dropIfExists('hr_skill_gaps');

Schema::dropIfExists('hr_skill_demand');

Schema::dropIfExists('hr_skill_forecasts');

Schema::dropIfExists('hr_skill_governance');

    }
};
