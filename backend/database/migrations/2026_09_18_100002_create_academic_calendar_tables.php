<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4 — Academic Calendar Engine (fondasi tabel).
 *
 * Event akademik, jenis event, dan hari efektif per semester.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= JENIS EVENT =================
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('color', 10)->nullable(); // warna di kalender (hex)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= EVENT AKADEMIK =================
        Schema::create('academic_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->string('location', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['semester_id', 'start_date']);
        });

        // ================= HARI EFEKTIF =================
        Schema::create('effective_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->boolean('is_school_day')->default(true);
            $table->string('note', 150)->nullable();
            $table->timestamps();

            // Satu tanggal tidak boleh dobel untuk (semester, jenjang) yang sama
            $table->unique(['semester_id', 'education_level_id', 'date'], 'effective_day_uniq');
            $table->index(['semester_id', 'is_school_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effective_days');
        Schema::dropIfExists('academic_events');
        Schema::dropIfExists('event_types');
    }
};