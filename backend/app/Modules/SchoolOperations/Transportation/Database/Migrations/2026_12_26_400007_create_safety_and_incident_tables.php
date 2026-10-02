<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keselamatan: checklist pra/pasca trip, insiden + tindakan + lampiran,
 * dan barang hilang/temuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_trip_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->timestamp('inspected_at');
            $table->string('result', 20)->default('PASSED');    // PASSED, FAILED, CONDITIONAL
            $table->boolean('critical_failure')->default(false);
            $table->boolean('blocks_dispatch')->default(false);
            $table->unsignedInteger('items_checked')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->decimal('odometer', 12, 2)->nullable();
            $table->string('fuel_level', 20)->nullable();
            $table->text('notes')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('device_id')->nullable();
            $table->boolean('offline_sync')->default(false);
            $table->timestamps();
        });

        Schema::create('pre_trip_inspection_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inspection_id')->index();
            $table->string('item_code', 40);
            $table->string('item_label');
            $table->string('category', 30)->nullable();          // VEHICLE, SAFETY, DOCUMENT, EQUIPMENT
            $table->boolean('mandatory')->default(true);
            $table->boolean('critical')->default(false);
            $table->string('result', 20)->default('OK');         // OK, ATTENTION, FAILED, NA
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('post_trip_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->timestamp('inspected_at');
            $table->string('vehicle_condition', 20)->default('GOOD'); // GOOD, MINOR_DAMAGE, MAJOR_DAMAGE
            $table->decimal('odometer', 12, 2)->nullable();
            $table->string('fuel_level', 20)->nullable();
            $table->boolean('damage_found')->default(false);
            $table->text('damage_description')->nullable();
            $table->boolean('lost_items_found')->default(false);
            $table->boolean('passenger_issues')->default(false);
            $table->text('passenger_issue_notes')->nullable();
            $table->boolean('incident_occurred')->default(false);
            $table->boolean('cleaning_required')->default(false);
            $table->boolean('maintenance_required')->default(false);
            $table->unsignedBigInteger('maintenance_reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('transportation_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number', 40)->unique();
            $table->string('incident_type', 30);                 // ACCIDENT, BREAKDOWN, MEDICAL, ...
            $table->string('severity', 20)->default('LOW');      // LOW, MEDIUM, HIGH, CRITICAL
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('route_id')->nullable();
            $table->unsignedBigInteger('stop_id')->nullable();
            $table->timestamp('occurred_at');
            $table->string('location_description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('description');
            $table->unsignedInteger('injured_count')->default(0);
            $table->boolean('passengers_involved')->default(false);
            $table->string('status', 24)->default('REPORTED');   // REPORTED, ACKNOWLEDGED, IN_PROGRESS, ESCALATED, RESOLVED, CLOSED
            $table->boolean('escalated')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedBigInteger('escalated_to')->nullable();
            $table->json('emergency_actions')->nullable();       // §48 kontak & tindakan darurat
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->string('reported_by_type', 20)->default('DRIVER');
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution')->nullable();
            $table->boolean('parent_notified')->default(false);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->boolean('offline_sync')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id')->index();
            $table->string('action_type', 30);                   // NOTIFY_PARENT, DISPATCH_REPLACEMENT, ESCALATE, ...
            $table->text('description')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('performed_at');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('DONE');
            $table->timestamps();
        });

        Schema::create('incident_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id')->index();
            $table->string('attachment_type', 30)->default('PHOTO'); // PHOTO, DOCUMENT, VIDEO, AUDIO, STATEMENT
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->text('caption')->nullable();
            $table->timestamps();
        });

        Schema::create('lost_found_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 40)->unique();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->string('found_location')->nullable();
            $table->unsignedBigInteger('stop_id')->nullable();
            $table->date('found_date');
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('FOUND');      // FOUND, IDENTIFIED, NOTIFIED, RETURNED, DISPOSED, UNCLAIMED
            $table->unsignedBigInteger('identified_by')->nullable();
            $table->timestamp('identified_at')->nullable();
            $table->boolean('owner_notified')->default(false);
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('returned_to')->nullable();
            $table->string('returned_to_name')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'lost_found_items', 'incident_attachments', 'incident_actions', 'transportation_incidents',
            'post_trip_inspections', 'pre_trip_inspection_items', 'pre_trip_inspections',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
