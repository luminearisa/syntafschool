<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roster tugas keagamaan, penugasan harian, dan penugasan pengganti (substitute).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('religious_duty_rosters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('roster_type', 20)->default('WEEKLY');
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_duty_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('roster_id')->index();
            $table->date('duty_date');
            $table->string('day_of_week', 12)->nullable();
            $table->string('prayer_type', 30)->nullable();
            $table->string('duty_role', 20)->default('IMAM');
            $table->unsignedBigInteger('personnel_id')->nullable()->index();
            $table->string('personnel_name', 160)->nullable();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('status', 20)->default('ASSIGNED');
            $table->unsignedBigInteger('substitute_for_id')->nullable()->index();
            $table->boolean('is_substitute')->default(false);
            $table->string('source', 20)->default('ROSTER');
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_substitutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assignment_id')->index();
            $table->unsignedBigInteger('original_personnel_id')->nullable()->index();
            $table->unsignedBigInteger('substitute_personnel_id')->index();
            $table->string('reason', 20)->default('UNAVAILABLE');
            $table->text('reason_detail')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->dateTime('notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('religious_substitutions');
        Schema::dropIfExists('religious_duty_assignments');
        Schema::dropIfExists('religious_duty_rosters');
    }
};
