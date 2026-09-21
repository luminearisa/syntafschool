<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eksemplar koleksi & keanggotaan (Fase 3).
 *
 * - `library_items` = eksemplar fisik/digital yang sebenarnya; satu
 *   bibliographic record boleh punya banyak item (Rule 1) dan satu item hanya
 *   menunjuk satu record (Rule 2).
 * - `library_members` menautkan orang yang sudah ada (siswa/pegawai/wali/user)
 *   atau data mandiri untuk anggota umum — data induk tidak diduplikasi
 *   (Rule 11).
 * - `library_membership_types` adalah master (dikelola lewat registry) yang
 *   menyimpan policy pinjam sebagai referensi; penerapannya di Fase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();

            // Identifier sistem (NumberingService) vs nomor inventaris (§21).
            // Penomoran per-perpustakaan → keunikan juga per-perpustakaan.
            $table->string('item_code', 50);
            $table->string('accession_number', 50)->nullable();

            // Barcode & RFID unik bila diisi (Rule 3 & 4).
            $table->string('barcode', 50)->nullable()->unique();
            $table->string('rfid_tag', 100)->nullable()->unique();
            $table->enum('rfid_status', ['unassigned', 'assigned', 'active', 'lost', 'disabled'])
                ->default('unassigned');

            $table->foreignId('location_id')->nullable()->constrained('library_locations')->nullOnDelete();
            $table->foreignId('condition_id')->nullable()->constrained('library_collection_conditions')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('library_collection_statuses')->nullOnDelete();

            // Status lifecycle item. `on_loan`/`reserved` disiapkan tetapi
            // pengendaliannya milik modul Circulation (Fase 4).
            $table->enum('status', [
                'available', 'on_loan', 'reserved', 'processing',
                'reference_only', 'lost', 'damaged', 'withdrawn', 'inactive',
            ])->default('available');

            $table->date('acquisition_date')->nullable();
            $table->foreignId('acquisition_source_id')->nullable()->constrained('library_acquisition_sources')->nullOnDelete();
            $table->decimal('acquisition_price', 14, 2)->nullable();
            $table->string('funding_source', 100)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'item_code']);
            $table->index(['bibliography_id', 'status']);
            $table->index(['library_id', 'status']);
            $table->index('location_id');
        });

        Schema::create('library_membership_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Policy pinjam sebagai REFERENSI; penerapan di Fase 4.
            $table->unsignedSmallInteger('max_loan')->default(3);
            $table->unsignedSmallInteger('loan_duration_days')->default(7);
            $table->unsignedSmallInteger('renewal_limit')->default(1);
            $table->boolean('can_reserve')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();

            // Tautan ke orang yang sudah ada — biodata tidak diinput ulang.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();

            // Identitas mandiri (anggota umum/alumni) bila tidak ada tautan.
            // Memakai master `genders` yang sama dengan siswa/pegawai.
            $table->string('name', 150)->nullable();
            $table->foreignId('gender_id')->nullable()->constrained('genders')->nullOnDelete();
            $table->date('birth_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('identity_number', 50)->nullable();
            $table->string('photo_path')->nullable();

            $table->foreignId('membership_type_id')->nullable()->constrained('library_membership_types')->nullOnDelete();

            // Nomor anggota dari penomoran per-perpustakaan → unik per perpustakaan.
            $table->string('member_number', 50);
            $table->date('registered_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->enum('status', ['pending', 'active', 'expired', 'suspended', 'blocked', 'inactive'])
                ->default('pending');

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'member_number']);

            // Satu orang hanya boleh punya satu membership aktif per library
            // (Rule 7) — ditegakkan di service karena melibatkan status.
            $table->index(['library_id', 'status']);
            $table->index(['student_id', 'library_id']);
            $table->index(['employee_id', 'library_id']);
            $table->index(['guardian_id', 'library_id']);
            $table->index('member_number');
        });

        Schema::create('library_member_cards', function (Blueprint $table) {
            $table->id();
            // Perpustakaan pemilik kartu — agar penomoran per-perpustakaan
            // tetap unik tanpa membentur kartu perpustakaan lain.
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->string('card_number', 50);
            // Token QR yang dipakai sirkulasi (Fase 4) & verifikasi.
            $table->string('qr_token', 64)->unique();
            $table->string('barcode', 50)->nullable()->unique();
            $table->date('issued_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
            $table->unsignedInteger('printed_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamps();

            // Nomor kartu unik per perpustakaan; QR & barcode unik global
            // karena dipakai pemindaian.
            $table->unique(['library_id', 'card_number']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_member_cards');
        Schema::dropIfExists('library_members');
        Schema::dropIfExists('library_membership_types');
        Schema::dropIfExists('library_items');
    }
};