<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Armada: profil kendaraan (terhubung ke Asset Management), kapasitas,
 * perlengkapan, dokumen, BBM, inspeksi kendaraan, dan kendaraan sewa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_code', 40)->unique();
            $table->unsignedBigInteger('asset_id')->nullable()->index();      // sumber: Asset Management
            $table->unsignedBigInteger('vehicle_type_id')->nullable()->index();
            $table->string('vehicle_type', 40)->nullable();                   // salinan kode untuk filter cepat
            $table->string('registration_number', 30)->nullable()->index();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            // §8 kapasitas dipisah
            $table->unsignedInteger('total_capacity')->default(0);
            $table->unsignedInteger('student_capacity')->default(0);
            $table->unsignedInteger('adult_capacity')->default(0);
            $table->unsignedInteger('standing_capacity')->default(0);
            $table->unsignedInteger('wheelchair_capacity')->default(0);
            $table->unsignedInteger('reserved_capacity')->default(0);
            $table->string('fuel_type', 20)->nullable();
            $table->string('transmission', 20)->nullable();
            $table->decimal('odometer', 12, 2)->default(0);
            $table->string('status', 24)->default('AVAILABLE');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('home_location_id')->nullable();
            $table->unsignedBigInteger('ownership_type_id')->nullable();      // owned/rental dari referensi asset
            $table->string('ownership_type', 20)->default('OWNED');           // OWNED, RENTAL, PARTNER, TEMPORARY
            $table->string('gps_device_id')->nullable();
            $table->unsignedInteger('current_mileage_since_service')->nullable();
            $table->date('last_service_date')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicle_equipment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->string('equipment_type', 40);            // SEAT_BELT, FIRST_AID_KIT, ...
            $table->string('label')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 20)->default('AVAILABLE'); // AVAILABLE, MISSING, DAMAGED, EXPIRED, MAINTENANCE
            $table->boolean('mandatory')->default(false);
            $table->boolean('critical')->default(false);      // memblokir dispatch bila gagal
            $table->date('expiry_date')->nullable();
            $table->date('last_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'equipment_type'], 'veh_equipment_unique');
        });

        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->string('document_type', 40);              // STNK, KIR, INSURANCE, ...
            $table->string('document_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->string('file_path')->nullable();
            $table->string('verification_status', 20)->default('PENDING'); // PENDING, VERIFIED, REJECTED, EXPIRED
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('blocks_dispatch')->default(false);
            $table->unsignedInteger('reminder_days')->default(30);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->string('fuel_type', 20)->nullable();
            $table->decimal('fuel_quantity', 10, 2);
            $table->decimal('fuel_cost', 14, 2)->default(0);
            $table->decimal('price_per_unit', 12, 2)->nullable();
            $table->decimal('odometer', 12, 2)->nullable();
            $table->date('fuel_date');
            $table->string('station')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->string('inspection_type', 30);            // PERIODIC, PRE_TRIP, POST_TRIP, ADHOC
            $table->date('inspection_date');
            $table->date('next_inspection_date')->nullable();
            $table->string('result', 20)->default('PASSED');  // PASSED, FAILED, CONDITIONAL
            $table->string('workshop')->nullable();
            $table->unsignedBigInteger('work_order_id')->nullable();   // tautan Maintenance
            $table->decimal('cost', 14, 2)->nullable();
            $table->text('findings')->nullable();
            $table->unsignedBigInteger('inspected_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_rentals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();    // Vendor Management
            $table->string('vendor_name')->nullable();
            $table->string('contract_number')->nullable();
            $table->date('rental_start');
            $table->date('rental_end')->nullable();
            $table->decimal('rental_cost', 14, 2)->nullable();
            $table->string('cost_basis', 20)->nullable();      // PER_DAY, PER_TRIP, PER_MONTH
            $table->string('insurance_reference')->nullable();
            $table->string('driver_arrangement', 20)->nullable();  // WITH_DRIVER, WITHOUT_DRIVER
            $table->string('status', 20)->default('ACTIVE');   // ACTIVE, ENDED, CANCELLED
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'vehicle_rentals', 'vehicle_inspections', 'vehicle_fuel_logs', 'vehicle_documents',
            'vehicle_equipment', 'vehicle_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
