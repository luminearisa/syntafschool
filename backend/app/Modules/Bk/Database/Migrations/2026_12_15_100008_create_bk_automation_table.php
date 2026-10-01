<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak otomasi & kunci idempotensinya.
 *
 * Satu baris per tindakan otomatis yang dijalankan, dengan kunci unik yang membuat
 * penjadwalan berulang tetap menghasilkan satu tindakan.
 *
 * Catatan desain: kolom status/kategori memakai `string` dengan daftar nilai yang
 * dijaga PHP Enum, bukan `enum()` basis data. Alasannya: modul BK sudah menambah
 * nilai beberapa kali, dan CHECK constraint bawaan `enum()` membuat penambahan
 * nilai baru selalu berarti migrasi pemecahan tabel — di MySQL maupun SQLite.
 * Validasi tetap ada, hanya dipindahkan ke tempat yang benar (enum + Form Request).
 *
 * `school_id` nullable adalah penopang isolasi unit sekolah. Instalasi satu
 * sekolah membiarkannya kosong dan perilaku modelnya sama seperti sebelumnya;
 * instalasi yayasan dengan beberapa unit mengisinya, dan global scope
 * `BelongsToSchool` menutup data antar-unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('action', 48);                        // REFERRAL_ASSIGN_QUEUE|CASE_ASSIGNED_NOTIFY|APPOINTMENT_REMINDER|FOLLOW_UP_REMINDER|FOLLOW_UP_ESCALATION|SLA_BREACH|RISK_REVIEW|CONSENT_EXPIRING|CASE_READY_TO_CLOSE|EXPORT_READY
            $table->string('subject_type', 48)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('channel', 24)->default('DATABASE');  // DATABASE|QUEUE|MAIL|PUSH
            $table->string('status', 20)->default('DONE');       // DONE|SKIPPED|FAILED
            $table->unsignedInteger('recipients')->default(0);
            $table->json('payload')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('ran_at');
            /*
             | Kunci idempotensi otomasi.
             |
             | Inilah yang membuat "kirim pengingat janji besok" tidak berubah menjadi
             | lima pesan karena cron berjalan lima kali, atau "naikkan ke koordinator"
             | tidak melahirkan lima eskalasi karena pekerja diulang setelah gagal.
             | Kunci memuat aksi + subjek + periode (hari) sehingga tindakan yang sama
             | pada hari yang sama hanya dijalankan sekali.
             */
            $table->string('dedup_key', 191)->unique();
            $table->timestamps();

            $table->index(['action', 'ran_at'], 'bk_automation_action');
            $table->index(['subject_type', 'subject_id'], 'bk_automation_subject');
            $table->index(['school_id', 'status'], 'bk_automation_school');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_automation_runs');
    }
};
