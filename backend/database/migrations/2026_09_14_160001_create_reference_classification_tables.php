<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('religions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('genders', function (Blueprint $table) {
            $table->id();
            $table->char('code', 1)->unique();
            $table->string('name', 20)->unique();
            $table->timestamps();
        });

        Schema::create('occupations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('education_backgrounds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->integer('level_number')->nullable();
            $table->timestamps();
        });

        Schema::create('income_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->decimal('min_value', 15, 2)->nullable();
            $table->decimal('max_value', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('guardian_relations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('residence_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('transportations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('student_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_statuses');
        Schema::dropIfExists('transportations');
        Schema::dropIfExists('residence_types');
        Schema::dropIfExists('guardian_relations');
        Schema::dropIfExists('income_ranges');
        Schema::dropIfExists('education_backgrounds');
        Schema::dropIfExists('occupations');
        Schema::dropIfExists('genders');
        Schema::dropIfExists('religions');
    }
};