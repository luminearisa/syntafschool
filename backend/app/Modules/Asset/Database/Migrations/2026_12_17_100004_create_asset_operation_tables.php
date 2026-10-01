<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operasi aset: pemegang (custodian), penugasan, pemindahan, peminjaman, dan reservasi.
 *
 * Riwayat tidak pernah ditimpa: `asset_custodian_history` adalah baris-baris yang
 * dibuka dan ditutup (assigned_at/released_at), bukan kolom "pemegang saat ini" yang
 * ditulis ulang. Pertanyaan "siapa memegang laptop ini bulan lalu" karena itu tetap
 * dapat dijawab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_custodians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('custodian_type', 24);
            $table->string('name', 160);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('employee_id', 60)->nullable();
            $table->string('position', 120)->nullable()->comment('Jabatan saat dicatat');
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['custodian_type', 'user_id']);
        });
        Schema::create('asset_custodian_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('custodian_id')->nullable()->constrained('asset_custodians')->nullOnDelete();
            $table->string('custodian_type', 24)->nullable();
            $table->string('custodian_label', 160)->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('released_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 200)->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained('asset_transfers')->nullOnDelete();
            $table->string('handover_document', 200)->nullable();
            $table->string('acknowledgement', 40)->nullable()->comment('DIGITAL | PAPER | NONE');
            $table->timestamps();
            $table->index(['asset_id', 'released_at']);
        });
        Schema::create('asset_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('assignment_number', 40)->unique();
            $table->string('type', 24)->default('INDIVIDUAL');
            $table->string('status', 24)->default('REQUESTED');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('custodian_id')->nullable()->constrained('asset_custodians')->nullOnDelete();
            $table->string('purpose', 200)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->string('acknowledgement_condition', 24)->nullable();
            $table->text('acknowledgement_notes')->nullable();
            $table->string('document_reference', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('asset_assignment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('asset_assignments')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('condition_before', 24)->nullable();
            $table->string('condition_after', 24)->nullable();
            $table->json('accessories')->nullable();
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'asset_id']);
        });
        Schema::create('asset_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('transfer_number', 40)->unique();
            $table->string('type', 32)->default('ROOM_TRANSFER');
            $table->string('status', 24)->default('REQUESTED');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->cascadeOnDelete()->comment('Tujuan wajib: pemindahan tanpa tujuan bukan pemindahan');
            $table->foreignId('from_custodian_id')->nullable()->constrained('asset_custodians')->nullOnDelete();
            $table->foreignId('to_custodian_id')->nullable()->constrained('asset_custodians')->nullOnDelete();
            $table->foreignId('from_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('to_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('handed_over_at')->nullable();
            $table->foreignId('handover_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_condition', 24)->nullable();
            $table->text('recipient_notes')->nullable();
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->string('document_reference', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'type']);
        });
        Schema::create('asset_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('asset_transfers')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('condition_before', 24)->nullable();
            $table->string('condition_after', 24)->nullable();
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->unique(['transfer_id', 'asset_id']);
        });
        Schema::create('asset_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('loan_number', 40)->unique();
            $table->string('status', 24)->default('REQUESTED');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('borrower_id')->constrained('users')->cascadeOnDelete();
            $table->string('borrower_type', 24)->default('STAFF');
            $table->string('unit_id')->nullable();
            $table->string('purpose', 200);
            $table->string('activity_reference', 80)->nullable()->comment('Rujukan kegiatan/agenda pemakai (lintas modul)');
            $table->dateTime('start_at');
            $table->dateTime('due_at');
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition_out', 24)->nullable();
            $table->string('condition_in', 24)->nullable();
            $table->json('accessories')->nullable();
            $table->unsignedBigInteger('damage_report_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->dateTime('overdue_notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'due_at']);
            $table->index('borrower_id');
        });
        Schema::create('asset_loan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('asset_loans')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('condition_out', 24)->nullable();
            $table->string('condition_in', 24)->nullable();
            $table->boolean('returned')->default(false);
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->unique(['loan_id', 'asset_id']);
        });
        Schema::create('asset_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('loan_id')->constrained('asset_loans')->cascadeOnDelete();
            $table->dateTime('returned_at');
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->string('condition', 24);
            $table->json('accessories')->nullable();
            $table->boolean('damage_found')->default(false);
            $table->unsignedBigInteger('damage_report_id')->nullable();
            $table->unsignedBigInteger('maintenance_request_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('asset_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('reserved_by')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 200)->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 24)->default('HELD');
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('loan_id')->nullable()->constrained('asset_loans')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'status', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_reservations');
        Schema::dropIfExists('asset_returns');
        Schema::dropIfExists('asset_loan_items');
        Schema::dropIfExists('asset_loans');
        Schema::dropIfExists('asset_transfer_items');
        Schema::dropIfExists('asset_transfers');
        Schema::dropIfExists('asset_assignment_items');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('asset_custodian_history');
        Schema::dropIfExists('asset_custodians');
    }
};
