<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Competition (School Operations Fase 5) — create_competition_infrastructure_tables.
 *
 * Catatan integrasi (§110): modul ini hanya menyimpan keadaan khas kompetisi —
 * pendaftaran, pertandingan, nilai, hasil, penghargaan. Data siswa, pegawai,
 * keuangan, fasilitas, dan pencapaian tetap milik modul asalnya dan hanya
 * dirujuk lewat kolom `_id` tanpa kunci asing keras.
 *
 * Kunci asing keras (cascade) hanya dipakai pada tabel yang benar-benar terikat
 * siklus hidup induknya, mis. criterion milik rubric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_notifications', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->unsignedBigInteger('registration_id')->nullable()->index();
            $t->unsignedBigInteger('participant_id')->nullable()->index();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->string('notification_event', 60)->index();
            $t->string('channel', 20)->index()->default('EMAIL');
            $t->string('recipient_type', 30)->index()->default('STUDENT');
            $t->unsignedBigInteger('recipient_id')->nullable()->index();
            $t->string('recipient_address', 200)->nullable();
            $t->string('subject', 255)->nullable();
            $t->text('body')->nullable();
            $t->string('status', 20)->index()->default('PENDING');
            $t->string('provider_reference', 120)->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->text('failed_reason')->nullable();
            $t->json('payload')->nullable();
            $t->string('idempotency_key', 120)->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('competition_id')->nullable()->index();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->unsignedBigInteger('actor_id')->nullable()->index();
            $t->string('actor_name', 150)->nullable();
            $t->string('actor_role', 60)->nullable();
            $t->string('action', 80)->index();
            $t->string('entity_type', 150)->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->json('old_value')->nullable();
            $t->json('new_value')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->unsignedBigInteger('campus_id')->nullable()->index();
            $t->dateTime('created_at')->index();
        });
        Schema::create('competition_domain_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('edition_id')->nullable()->index();
            $t->string('event_name', 80)->index();
            $t->string('aggregate_type', 150)->nullable();
            $t->unsignedBigInteger('aggregate_id')->nullable()->index();
            $t->json('payload')->nullable();
            $t->json('channels')->nullable();
            $t->boolean('published')->index()->default(false);
            $t->dateTime('published_at')->nullable();
            $t->dateTime('created_at')->nullable();
        });
        Schema::create('competition_idempotency_keys', function (Blueprint $t): void {
            $t->id();
            $t->string('idempotency_key', 150)->unique();
            $t->string('scope', 60)->index();
            $t->string('source', 20)->default('MANUAL');
            $t->string('external_reference', 150)->nullable();
            $t->string('device_id', 120)->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('competition_sync_batches', function (Blueprint $t): void {
            $t->id();
            $t->string('device_id', 120)->nullable()->index();
            $t->string('scope', 60)->index();
            $t->integer('item_count')->default(0);
            $t->integer('accepted_count')->default(0);
            $t->integer('rejected_count')->default(0);
            $t->integer('conflict_count')->default(0);
            $t->string('status', 20)->index()->default('PENDING');
            $t->json('payload')->nullable();
            $t->json('error_summary')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('competition_offline_drafts', function (Blueprint $t): void {
            $t->id();
            $t->string('device_id', 120)->index();
            $t->string('scope', 60)->index();
            $t->string('entity_type', 150)->nullable();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->json('payload')->nullable();
            $t->string('status', 20)->index()->default('QUEUED');
            $t->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $t->dateTime('queued_at')->nullable();
            $t->dateTime('synced_at')->nullable();
            $t->text('conflict_reason')->nullable();
            $t->string('idempotency_key', 150)->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_offline_drafts');
        Schema::dropIfExists('competition_sync_batches');
        Schema::dropIfExists('competition_idempotency_keys');
        Schema::dropIfExists('competition_domain_events');
        Schema::dropIfExists('competition_audit_logs');
        Schema::dropIfExists('competition_notifications');
    }
};
