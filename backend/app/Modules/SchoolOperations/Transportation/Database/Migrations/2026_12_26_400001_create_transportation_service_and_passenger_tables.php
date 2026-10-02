<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layanan transportasi, profil transportasi siswa, otorisasi orang tua,
 * penjemput yang diizinkan, dan registry perangkat lapangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportation_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('service_type', 40);           // SCHOOL_BUS, SHUTTLE, ...
            $table->text('description')->nullable();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->boolean('billable')->default(false);  // §61 integrasi Finance
            $table->decimal('fee_amount', 14, 2)->nullable();
            $table->unsignedInteger('max_passengers_per_route')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('transportation_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();         // BUS, MINIBUS, VAN, CAR, ...
            $table->string('name');
            $table->string('license_requirement', 30)->nullable(); // kelas SIM minimal
            $table->unsignedInteger('default_passenger_capacity')->nullable();
            $table->unsignedInteger('default_standing_capacity')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('student_transport_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->boolean('transport_required')->default(false);
            $table->string('transport_type', 40)->nullable();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('pickup_stop_id')->nullable()->index();
            $table->unsignedBigInteger('dropoff_stop_id')->nullable()->index();
            $table->unsignedBigInteger('morning_route_id')->nullable()->index();
            $table->unsignedBigInteger('afternoon_route_id')->nullable()->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('special_requirement', 60)->nullable();
            $table->string('special_requirement_note')->nullable();
            $table->boolean('escort_required')->default(false);
            $table->boolean('wheelchair_required')->default(false);
            $table->boolean('authorized_pickup_restricted')->default(false);
            $table->string('status', 20)->default('ACTIVE');   // ACTIVE, SUSPENDED, ENDED
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status'], 'stp_student_status_idx');
        });

        Schema::create('parent_transport_authorizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('guardian_id')->nullable()->index();
            $table->string('authorization_type', 30)->default('RECURRING'); // RECURRING, TEMPORARY, EMERGENCY
            $table->unsignedBigInteger('pickup_stop_id')->nullable()->index();
            $table->unsignedBigInteger('dropoff_stop_id')->nullable()->index();
            $table->boolean('transportation_permission')->default(true);
            $table->string('authorized_person_name')->nullable();
            $table->string('authorized_person_phone', 30)->nullable();
            $table->text('special_instruction')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->index(['student_id', 'status'], 'pta_student_status_idx');
        });

        Schema::create('authorized_pickup_persons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->index();
            $table->string('name');
            $table->string('relationship', 40)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('identity_type', 30)->nullable();
            $table->string('identity_number')->nullable();     // disimpan terenkripsi ringan/hash di produksi
            $table->string('identity_hash', 64)->nullable()->index();
            $table->string('photo_path')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('ACTIVE');   // ACTIVE, EXPIRED, REVOKED
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status'], 'app_student_status_idx');
        });

        Schema::create('transportation_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique();
            $table->string('device_type', 30);                 // DRIVER_DEVICE, RFID_READER, ...
            $table->string('app_version', 30)->nullable();
            $table->string('platform', 30)->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->string('api_key_hash', 64)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'transportation_devices', 'authorized_pickup_persons', 'parent_transport_authorizations',
            'student_transport_profiles', 'transportation_vehicle_types', 'transportation_services',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
