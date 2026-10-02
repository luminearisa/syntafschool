<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('hr_opportunity_types', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->integer('duration_days')->default(0);
            $table->decimal('allocation_default', 8, 2)->nullable();
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_talent_opportunities', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('opportunity_type_code')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('department_id')->nullable()->index();
            $table->foreignId('location_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->index();
            $table->foreignId('job_id')->nullable()->index();
            $table->string('project_reference')->nullable();
            $table->integer('duration_days')->default(0);
            $table->decimal('allocation_percent', 8, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('required_skills')->nullable();
            $table->json('preferred_skills')->nullable();
            $table->decimal('min_experience_years', 8, 2)->nullable();
            $table->string('required_certification')->nullable();
            $table->string('visibility')->nullable();
            $table->string('status')->nullable();
            $table->integer('spots')->default(1);
            $table->foreignId('filled_by_employee_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->index();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_opportunity_requirements', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->index();
            $table->string('requirement_type')->nullable();
            $table->string('key')->index()->nullable();
            $table->string('required_level')->nullable();
            $table->decimal('required_value', 18, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_employee_opportunity_matches', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->index();
            $table->foreignId('employee_id')->nullable()->index();
            $table->decimal('match_score', 8, 2)->nullable();
            $table->decimal('skill_score', 8, 2)->nullable();
            $table->decimal('experience_score', 8, 2)->nullable();
            $table->decimal('interest_score', 8, 2)->nullable();
            $table->decimal('career_goal_score', 8, 2)->nullable();
            $table->decimal('location_score', 8, 2)->nullable();
            $table->decimal('availability_score', 8, 2)->nullable();
            $table->decimal('certification_score', 8, 2)->nullable();
            $table->decimal('performance_score', 8, 2)->nullable();
            $table->json('factors')->nullable();
            $table->string('recommendation')->nullable();
            $table->string('human_review_status')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_employee_interests', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('interest_type')->nullable();
            $table->string('key')->index()->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_employee_availability', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->date('as_of')->nullable();
            $table->decimal('capacity_percent', 8, 2)->nullable();
            $table->decimal('allocated_percent', 8, 2)->nullable();
            $table->decimal('available_percent', 8, 2)->nullable();
            $table->date('available_from')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_project_assignments', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->foreignId('opportunity_id')->nullable()->index();
            $table->string('project_code')->index()->nullable();
            $table->string('project_name')->nullable();
            $table->string('role_title')->nullable();
            $table->decimal('allocation_percent', 8, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('primary_job_overlap')->default(true);
            $table->string('status')->nullable();
            $table->foreignId('manager_employee_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });


        Schema::create('hr_temporary_assignments', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('assignment_type')->nullable();
            $table->foreignId('from_position_id')->nullable()->index();
            $table->foreignId('to_position_id')->nullable()->index();
            $table->foreignId('to_organization_id')->nullable()->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('workload_percent', 8, 2)->nullable();
            $table->string('status')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
Schema::dropIfExists('hr_opportunity_types');

Schema::dropIfExists('hr_talent_opportunities');

Schema::dropIfExists('hr_opportunity_requirements');

Schema::dropIfExists('hr_employee_opportunity_matches');

Schema::dropIfExists('hr_employee_interests');

Schema::dropIfExists('hr_employee_availability');

Schema::dropIfExists('hr_project_assignments');

Schema::dropIfExists('hr_temporary_assignments');

    }
};
