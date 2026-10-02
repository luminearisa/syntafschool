<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya operasional (bukan ledger akuntansi), notifikasi, penutupan harian,
 * penyesuaian pasca-closing, idempotensi event, jejak audit, dan event bus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportation_costs', function (Blueprint $table) {
            $table->id();
            $table->string('cost_number', 40)->unique();
            $table->string('cost_category', 24);                 // FUEL, DRIVER, TOLL, PARKING, MAINTENANCE, RENTAL, OTHER
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->unsignedBigInteger('route_id')->nullable()->index();
            $table->date('cost_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('source', 20)->default('MANUAL');     // MANUAL, FUEL_LOG, MAINTENANCE, RENTAL, PAYROLL
            $table->string('source_reference')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('receipt_number')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->boolean('billable')->default(false);
            $table->string('finance_status', 20)->default('PENDING'); // PENDING, POSTED, REJECTED
            $table->string('finance_reference')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['cost_date', 'cost_category'], 'tcost_date_category_idx');
        });

        Schema::create('transportation_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 40);                    // PassengerBoarded, TripDelayed, ...
            $table->string('audience', 20);                      // PARENT, DRIVER, ADMIN, STUDENT, SECURITY
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->string('channel', 20)->default('IN_APP');    // IN_APP, PUSH, WHATSAPP, EMAIL, SMS
            $table->string('title');
            $table->text('body');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('PENDING');    // PENDING, SENT, FAILED, SKIPPED
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status'], 'tnotif_event_status_idx');
        });

        Schema::create('transportation_daily_closings', function (Blueprint $table) {
            $table->id();
            $table->date('closing_date');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedInteger('trips_planned')->default(0);
            $table->unsignedInteger('trips_completed')->default(0);
            $table->unsignedInteger('trips_cancelled')->default(0);
            $table->unsignedInteger('passengers_transported')->default(0);
            $table->unsignedInteger('no_shows')->default(0);
            $table->unsignedInteger('incidents')->default(0);
            $table->decimal('total_distance_km', 12, 2)->default(0);
            $table->decimal('total_fuel_cost', 14, 2)->default(0);
            $table->decimal('total_operational_cost', 14, 2)->default(0);
            $table->decimal('cost_per_passenger', 14, 2)->default(0);
            $table->decimal('on_time_rate', 5, 2)->default(0);
            $table->string('status', 20)->default('DRAFT');      // DRAFT, CLOSED, REOPENED
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['closing_date', 'campus_id'], 'daily_closing_unique');
        });

        Schema::create('transportation_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_type', 40);                // trip, closing, fuel_log, incident, ...
            $table->unsignedBigInteger('reference_id');
            $table->string('adjustment_type', 40);               // BOARDING_CORRECTION, DROPOFF_CORRECTION, COST_CORRECTION, CLOSING_REOPEN
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('status', 20)->default('APPLIED');    // PENDING, APPLIED, REJECTED
            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'tadjust_reference_idx');
        });

        Schema::create('transportation_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('scope', 60);                         // BOARDING, DROPOFF, TRIP_START, ...
            $table->string('source', 20)->nullable();
            $table->string('external_reference')->nullable();
            $table->string('device_id')->nullable();
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['scope', 'external_reference'], 'tidem_scope_ext_idx');
        });

        Schema::create('transportation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_type', 20)->default('USER');
            $table->string('campus_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id'], 'taudit_auditable_idx');
            $table->index(['event', 'created_at'], 'taudit_event_idx');
        });

        Schema::create('transportation_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name', 60)->index();
            $table->string('aggregate_type')->nullable();
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('PUBLISHED');  // PUBLISHED, FAILED, SKIPPED
            $table->unsignedInteger('listener_count')->default(0);
            $table->json('dispatch_result')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id'], 'tevent_aggregate_idx');
        });
    }

    public function down(): void
    {
        foreach ([
            'transportation_events', 'transportation_audit_logs', 'transportation_idempotency_keys',
            'transportation_adjustments', 'transportation_daily_closings', 'transportation_notifications',
            'transportation_costs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
