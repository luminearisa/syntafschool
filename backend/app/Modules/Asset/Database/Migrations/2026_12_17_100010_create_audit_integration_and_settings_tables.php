<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit, notifikasi, outbox integrasi, impor massal, dan setelan sekolah.
 *
 * Outbox integrasi adalah sebab modul ini tidak bergantung pada modul lain yang mungkin
 * belum terpasang: niat untuk mencatatkan sesuatu ke Keuangan/Persediaan/Pengadaan
 * disimpan lebih dulu (PENDING), lalu dikerjakan saat modul tujuannya tersedia. Niat itu
 * tidak pernah hilang hanya karena modul tujuan belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 60)->nullable();
            $table->string('status_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->string('from_location', 200)->nullable();
            $table->string('to_location', 200)->nullable();
            $table->string('reason', 200)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['asset_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });
        Schema::create('asset_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('event', 60);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_role', 60)->nullable();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('channel', 24)->default('database');
            $table->string('status', 24)->default('PENDING');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['event', 'status']);
        });
        Schema::create('asset_integration_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('module', 40)->comment('finance | inventory | procurement | workflow | document | notification');
            $table->string('action', 60);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('PENDING')->comment('PENDING | DISPATCHED | FAILED');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['module', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::create('asset_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('type', 40)->comment('assets | locations | facilities | asset_categories | custodians');
            $table->string('file_path', 200);
            $table->string('original_name', 200)->nullable();
            $table->string('status', 24)->default('UPLOADED')->comment('UPLOADED | VALIDATED | FAILED | IMPORTED | CANCELLED');
            $table->json('summary')->nullable()->comment('Jumlah baris: total, valid, gagal, dan contoh kesalahannya');
            $table->json('preview')->nullable();
            $table->string('error_path', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('asset_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('key', 80);
            $table->json('value')->nullable();
            $table->string('description', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_settings');
        Schema::dropIfExists('asset_imports');
        Schema::dropIfExists('asset_integration_outbox');
        Schema::dropIfExists('asset_notifications');
        Schema::dropIfExists('asset_audit_logs');
    }
};
