<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rute: rute (non-destruktif lewat versi), versi rute, dan halte dengan
 * geolokasi siap GPS/geofence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportation_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('route_type', 30);                 // PICKUP, DROPOFF, ROUND_TRIP, ...
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('origin_location_id')->nullable();
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->unsignedInteger('default_vehicle_capacity')->nullable();
            $table->unsignedInteger('max_stops')->nullable();
            $table->unsignedInteger('max_duration_minutes')->nullable();
            $table->time('pickup_window_start')->nullable();
            $table->time('pickup_window_end')->nullable();
            $table->time('dropoff_window_start')->nullable();
            $table->time('dropoff_window_end')->nullable();
            $table->time('school_arrival_deadline')->nullable();
            $table->time('school_departure_deadline')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 20)->default('DRAFT');    // DRAFT, ACTIVE, SUSPENDED, ARCHIVED
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('route_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id')->index();
            $table->unsignedInteger('version_number');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('DRAFT');    // DRAFT, PENDING_APPROVAL, ACTIVE, SUPERSEDED
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->json('snapshot')->nullable();              // ringkasan halte & parameter saat versi dibekukan
            $table->text('change_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'version_number'], 'route_version_unique');
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id')->index();
            $table->unsignedBigInteger('route_version_id')->nullable()->index();
            $table->unsignedInteger('sequence');
            $table->string('name');
            $table->string('stop_type', 30)->default('PICKUP_POINT'); // HOME, PICKUP_POINT, ...
            $table->string('address')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_meters')->default(100);
            $table->time('planned_arrival')->nullable();
            $table->time('planned_departure')->nullable();
            $table->boolean('pickup_allowed')->default(true);
            $table->boolean('dropoff_allowed')->default(true);
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('default_dwell_minutes')->nullable();
            $table->string('segment_distance_km')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['route_id', 'sequence'], 'route_stop_seq_idx');
        });

        Schema::create('route_stop_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_version_id')->index();
            $table->unsignedBigInteger('route_stop_id')->nullable()->index();
            $table->unsignedInteger('sequence');
            $table->json('attributes');                        // salinan atribut halte pada versi tersebut
            $table->string('change_type', 20)->default('SNAPSHOT'); // ADDED, UPDATED, REMOVED, SNAPSHOT
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['route_stop_versions', 'route_stops', 'route_versions', 'transportation_routes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
