<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Banding/review nilai siswa (§20, §59) beserta jejak audit
 * keputusannya (§53).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_grade_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->foreignId('grade_item_id')->nullable()->constrained('lms_grade_items')->nullOnDelete();
            $table->foreignId('assessment_result_id')->nullable()->constrained('lms_assessment_results')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('status', 20)->default('PENDING');        // GradeReviewStatus
            $table->text('reason');
            $table->decimal('current_score', 8, 2)->nullable();
            $table->decimal('requested_score', 8, 2)->nullable();
            $table->decimal('decided_score', 8, 2)->nullable();
            $table->string('decision', 20)->nullable();              // APPROVED | REJECTED
            $table->text('teacher_note')->nullable();
            $table->text('student_note')->nullable();
            $table->boolean('is_parent_visible')->default(true);
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['gradebook_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('lms_grade_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_review_id')->constrained('lms_grade_reviews')->cascadeOnDelete();
            $table->string('action', 30);                            // REQUESTED | UNDER_REVIEW | APPROVED | REJECTED | ADJUSTED | CLOSED | COMMENTED
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['grade_review_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_grade_review_events');
        Schema::dropIfExists('lms_grade_reviews');
    }
};
