<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shalat Jumat, penugasan petugas, kategori khutbah, khutbah + versinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_prayers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->date('prayer_date');
            $table->unsignedBigInteger('khatib_id')->nullable()->index();
            $table->unsignedBigInteger('imam_id')->nullable()->index();
            $table->unsignedBigInteger('muadzin_id')->nullable()->index();
            $table->string('topic', 200)->nullable();
            $table->string('theme', 120)->nullable();
            $table->string('language', 20)->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('estimated_attendance')->nullable();
            $table->unsignedInteger('actual_attendance')->nullable();
            $table->unsignedBigInteger('khutbah_id')->nullable()->index();
            $table->string('status', 20)->default('SCHEDULED');
            $table->boolean('is_substitute')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('friday_prayer_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('friday_prayer_id')->index();
            $table->string('duty_role', 20)->default('KHATIB');
            $table->unsignedBigInteger('personnel_id')->nullable()->index();
            $table->string('personnel_name', 160)->nullable();
            $table->string('status', 20)->default('ASSIGNED');
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedBigInteger('substitute_personnel_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('khutbah_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('khutbahs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title', 200);
            $table->string('theme', 120)->nullable();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('category_code', 60)->nullable();
            $table->unsignedBigInteger('khatib_id')->nullable()->index();
            $table->string('khatib_name', 160)->nullable();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->date('prayer_date')->nullable();
            $table->string('language', 20)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('reference_text')->nullable();
            $table->text('summary')->nullable();
            $table->text('content')->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('version_number')->default(1);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('khutbah_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('khutbah_id')->index();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('title', 200)->nullable();
            $table->text('content')->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->text('change_note')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->string('status', 20)->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khutbah_versions');
        Schema::dropIfExists('khutbahs');
        Schema::dropIfExists('khutbah_categories');
        Schema::dropIfExists('friday_prayer_assignments');
        Schema::dropIfExists('friday_prayers');
    }
};
