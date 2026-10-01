<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan orang tua, keanggotaan, daftar tunggu, pengunduran diri, dan pindah aktivitas.
 *
 * Keanggotaan adalah titik berat modul ini: satu siswa–satu program–satu periode
 * hanya boleh memiliki satu baris (unique), dan seluruh perubahannya meninggalkan
 * jejak di `activity_membership_histories` — riwayat tidak pernah ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->nullable()->constrained('activity_registrations')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('parent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 24)->default('PENDING')->index(); // PENDING|APPROVED|DECLINED|EXPIRED|REVOKED
            $table->text('notes')->nullable();
            $table->string('scope', 32)->default('PARTICIPATION'); // PARTICIPATION|TRIP|COMPETITION|EQUIPMENT
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['idempotency_key'], 'activity_consents_idempotency_unique');
            $table->index(['student_id', 'program_id', 'decision'], 'activity_consents_lookup');
        });

        Schema::create('activity_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('activity_periods')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('activity_registrations')->nullOnDelete();
            $table->string('role', 32)->default('MEMBER');
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->date('joined_at');
            $table->date('ended_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->boolean('leadership_eligible')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['program_id', 'period_id', 'student_id'], 'activity_memberships_unique_member');
        });

        Schema::create('activity_membership_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('activity_memberships')->cascadeOnDelete();
            $table->string('action', 32); // CREATED|SUSPENDED|REACTIVATED|ROLE_CHANGED|WITHDRAWN|TRANSFERRED|COMPLETED
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('from_role', 32)->nullable();
            $table->string('to_role', 32)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['membership_id', 'created_at'], 'activity_membership_histories_index');
        });

        Schema::create('activity_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->unique()->constrained('activity_registrations')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->integer('position');
            $table->string('status', 24)->default('WAITING')->index(); // WAITING|PROMOTED|CANCELLED|EXPIRED
            $table->timestamp('promoted_at')->nullable();
            $table->foreignId('promoted_membership_id')->nullable()->constrained('activity_memberships')->nullOnDelete();
            $table->string('promotion_key', 96)->nullable();
            $table->timestamps();
            $table->index(['program_id', 'status', 'position'], 'activity_waitlist_queue');
        });

        Schema::create('activity_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('activity_memberships')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->string('reason_code', 48)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('PENDING')->index(); // PENDING|APPROVED|REJECTED|CANCELLED
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->boolean('effective_immediately')->default(false);
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['idempotency_key'], 'activity_withdrawals_idempotency_unique');
        });

        Schema::create('activity_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('from_membership_id')->constrained('activity_memberships')->cascadeOnDelete();
            $table->foreignId('to_program_id')->constrained('activity_programs')->cascadeOnDelete();
            $table->foreignId('to_membership_id')->nullable()->constrained('activity_memberships')->nullOnDelete();
            $table->string('status', 24)->default('PENDING')->index(); // PENDING|APPROVED|REJECTED|CANCELLED
            $table->text('reason')->nullable();
            $table->json('conflict_snapshot')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('idempotency_key', 96)->nullable();
            $table->timestamps();
            $table->unique(['idempotency_key'], 'activity_transfers_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_transfers');
        Schema::dropIfExists('activity_withdrawals');
        Schema::dropIfExists('activity_waitlist_entries');
        Schema::dropIfExists('activity_membership_histories');
        Schema::dropIfExists('activity_memberships');
        Schema::dropIfExists('activity_consents');
    }
};
