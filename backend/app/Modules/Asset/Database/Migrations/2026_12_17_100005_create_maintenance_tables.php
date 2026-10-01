<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemeliharaan: permintaan, work order, tugas, jadwal preventif, catatan, dan kontrak vendor.
 *
 * Biaya dipecah menurut jenisnya (tenaga, suku cadang, jasa luar, transportasi, lain-lain)
 * karena hanya dengan pemecahan itu pertanyaan "perbaikan ini menghabiskan uang di mana"
 * dapat dijawab — dan hanya itu yang dapat dipetakan ke akun Keuangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('request_number', 40)->unique();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('source', 24)->default('USER')->comment('USER | INSPECTION | DAMAGE_REPORT | PREVENTIVE');
            $table->string('category', 40)->nullable();
            $table->string('priority', 24)->default('NORMAL');
            $table->string('status', 24)->default('NEW');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('photo_path', 200)->nullable();
            $table->dateTime('reported_at');
            $table->dateTime('sla_due_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->foreignId('triaged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('triaged_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->unsignedBigInteger('damage_report_id')->nullable();
            $table->decimal('cost_estimate', 16, 2)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'priority']);
            $table->index('sla_due_at');
        });
        Schema::create('maintenance_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->unsignedBigInteger('vendor_id')->comment('Rujukan lintas modul; nama vendor tidak disimpan sebagai teks bebas');
            $table->string('contract_number', 60)->unique();
            $table->string('title', 200);
            $table->date('start_date');
            $table->date('end_date');
            $table->json('sla')->nullable();
            $table->string('service_frequency', 40)->nullable();
            $table->decimal('cost', 16, 2)->default(0);
            $table->text('terms')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'end_date']);
        });
        Schema::create('maintenance_contract_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('maintenance_contracts')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('covered_from')->nullable();
            $table->date('covered_until')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'asset_id']);
        });
        Schema::create('maintenance_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('work_order_number', 40)->unique();
            $table->foreignId('request_id')->nullable()->constrained('maintenance_requests')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->string('type', 32)->default('CORRECTIVE');
            $table->string('status', 24)->default('DRAFT');
            $table->string('priority', 24)->default('NORMAL');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('repair_plan')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete()->comment('Teknisi penanggung jawab');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->decimal('labor_cost', 16, 2)->default(0);
            $table->decimal('parts_cost', 16, 2)->default(0);
            $table->decimal('external_cost', 16, 2)->default(0);
            $table->decimal('transport_cost', 16, 2)->default(0);
            $table->decimal('other_cost', 16, 2)->default(0);
            $table->decimal('total_cost', 16, 2)->default(0);
            $table->unsignedBigInteger('vendor_id')->nullable()->comment('Rujukan lintas modul');
            $table->foreignId('contract_id')->nullable()->constrained('maintenance_contracts')->nullOnDelete();
            $table->unsignedInteger('downtime_minutes')->default(0);
            $table->string('finance_status', 24)->default('PENDING');
            $table->string('finance_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'due_at']);
            $table->index('assigned_to');
        });
        Schema::create('maintenance_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('maintenance_work_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->string('title', 160);
            $table->string('frequency', 24)->default('DAYS_90');
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->json('checklist')->nullable();
            $table->dateTime('next_due_at');
            $table->dateTime('last_run_at')->nullable();
            $table->string('assigned_role', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('last_work_order_id')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_due_at']);
        });
        Schema::create('maintenance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('maintenance_work_orders')->cascadeOnDelete();
            $table->string('log_type', 32)->default('NOTE');
            $table->text('message');
            $table->json('meta')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_contract_assets');
        Schema::dropIfExists('maintenance_contracts');
        Schema::dropIfExists('maintenance_logs');
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('maintenance_tasks');
        Schema::dropIfExists('maintenance_work_orders');
        Schema::dropIfExists('maintenance_requests');
    }
};
