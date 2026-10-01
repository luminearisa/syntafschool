<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking fasilitas, agregat pemanfaatan harian, dan meter utilitas.
 *
 * Pemeriksaan benturan membaca tabel ini langsung (indeks facility_id + rentang waktu),
 * bukan struktur terpisah, sehingga tidak ada dua kebenaran tentang sebuah slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('booking_number', 40)->unique();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('source', 24)->default('STAFF');
            $table->string('status', 24)->default('REQUESTED');
            $table->string('title', 200);
            $table->text('purpose')->nullable();
            $table->unsignedInteger('attendee_count')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('requires_approval')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 200)->nullable();
            $table->string('event_reference', 80)->nullable();
            $table->string('activity_reference', 80)->nullable();
            $table->string('academic_reference', 80)->nullable();
            $table->dateTime('conflict_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['facility_id', 'start_at', 'end_at']);
            $table->index(['status', 'start_at']);
        });
        Schema::create('facility_utilization_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('booking_count')->default(0);
            $table->unsignedInteger('usage_minutes')->default(0);
            $table->unsignedInteger('attendee_count')->default(0);
            $table->decimal('capacity_utilization', 6, 2)->default(0);
            $table->string('peak_hour', 5)->nullable();
            $table->timestamps();
            $table->unique(['facility_id', 'date']);
        });
        Schema::create('utility_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('meter_type', 24);
            $table->string('meter_number', 80);
            $table->string('unit', 24)->default('kWh');
            $table->decimal('multiplier', 8, 2)->default(1);
            $table->decimal('current_reading', 16, 2)->nullable();
            $table->decimal('last_reading', 16, 2)->nullable();
            $table->dateTime('last_reading_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['meter_type', 'meter_number']);
        });
        Schema::create('utility_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('meter_id')->constrained('utility_meters')->cascadeOnDelete();
            $table->decimal('reading', 16, 2);
            $table->dateTime('reading_at');
            $table->decimal('consumption', 16, 2)->default(0);
            $table->decimal('unit_cost', 16, 2)->nullable();
            $table->decimal('total_cost', 16, 2)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finance_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['meter_id', 'reading_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_readings');
        Schema::dropIfExists('utility_meters');
        Schema::dropIfExists('facility_utilization_daily');
        Schema::dropIfExists('facility_bookings');
    }
};
