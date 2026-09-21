<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPDB — konfigurasi penerimaan (periode, gelombang, program, form builder,
 * pertanyaan tambahan, dokumen persyaratan, biaya).
 *
 * Semua aturan bersifat DATA: field form, pertanyaan (beserta scopnya), dokumen
 * persyaratan, dan biaya dibuat admin — bukan hardcode di kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppdb_periods', function (Blueprint $table) {
            $table->id();
            // Panitia pelaksana; akun pengguna existing dipakai ulang.
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            // Slug halaman publik, mis. /ppdb/2027.
            $table->string('slug', 100)->unique();
            $table->string('public_title', 200)->nullable();
            $table->text('public_description')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 100)->nullable();
            $table->text('contact_address')->nullable();
            $table->json('faq')->nullable();
            $table->date('registration_start')->nullable();
            $table->date('registration_end')->nullable();
            $table->date('selection_announcement_date')->nullable();
            $table->date('re_registration_start')->nullable();
            $table->date('re_registration_end')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'open', 'closed', 'archived'])->default('draft');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'academic_year_id']);
        });

        Schema::create('ppdb_waves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->date('registration_start')->nullable();
            $table->date('registration_end')->nullable();
            // Kuota gelombang; 0/null = tanpa batas.
            $table->unsignedSmallInteger('quota')->nullable();
            // Gelombang dengan prioritas kecil diproses lebih dahulu.
            $table->unsignedSmallInteger('priority')->default(1);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'code']);
            $table->index(['period_id', 'status']);
        });

        Schema::create('ppdb_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            // Tautan opsional ke master `programs` yang sudah ada (IPA/IPS/Bahasa).
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained('education_levels')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->unsignedSmallInteger('quota')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['period_id', 'code']);
            $table->index(['period_id', 'status']);
        });

        // Form builder: field yang ditampilkan ke calon.
        Schema::create('ppdb_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->string('field_key', 50);
            $table->string('label', 150);
            $table->enum('type', [
                'text', 'textarea', 'number', 'date', 'email', 'phone',
                'select', 'radio', 'checkbox', 'multi_select', 'file',
            ])->default('text');
            $table->string('section', 50)->default('data_calon');
            $table->string('placeholder', 150)->nullable();
            $table->text('description')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'field_key']);
            $table->index(['period_id', 'active', 'order']);
        });

        // Pertanyaan tambahan (scoped: global / per program / per gelombang).
        Schema::create('ppdb_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('ppdb_programs')->cascadeOnDelete();
            $table->foreignId('wave_id')->nullable()->constrained('ppdb_waves')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('question', 250);
            $table->text('description')->nullable();
            $table->enum('type', [
                'text', 'textarea', 'number', 'date', 'yes_no',
                'single_choice', 'multiple_choice', 'dropdown', 'file',
            ])->default('text');
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['period_id', 'code']);
            $table->index(['period_id', 'active', 'order']);
        });

        Schema::create('ppdb_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('ppdb_questions')->cascadeOnDelete();
            $table->string('label', 150);
            $table->string('value', 100)->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'order']);
        });

        Schema::create('ppdb_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('ppdb_programs')->cascadeOnDelete();
            $table->foreignId('wave_id')->nullable()->constrained('ppdb_waves')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            // Batasan berkas: daftar ekstensi dipisah koma, ukuran dalam kilobyte.
            $table->string('file_types', 100)->default('pdf,jpg,jpeg,png');
            $table->unsignedInteger('max_size_kb')->default(5120);
            $table->unsignedInteger('min_size_kb')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'code']);
            $table->index(['period_id', 'active', 'order']);
        });

        Schema::create('ppdb_registration_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('ppdb_programs')->cascadeOnDelete();
            $table->foreignId('wave_id')->nullable()->constrained('ppdb_waves')->cascadeOnDelete();
            $table->enum('type', ['registration', 'selection', 'test', 'psychology', 'other'])->default('registration');
            $table->string('name', 150);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('required')->default(true);
            $table->date('payment_deadline')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['period_id', 'type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_registration_fees');
        Schema::dropIfExists('ppdb_document_requirements');
        Schema::dropIfExists('ppdb_question_options');
        Schema::dropIfExists('ppdb_questions');
        Schema::dropIfExists('ppdb_form_fields');
        Schema::dropIfExists('ppdb_programs');
        Schema::dropIfExists('ppdb_waves');
        Schema::dropIfExists('ppdb_periods');
    }
};