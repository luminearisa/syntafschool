<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('npsn', 20)->nullable()->unique();
            $table->string('name', 150);
            $table->enum('jenjang', ['SD', 'SMP', 'SMA', 'SMK']);
            $table->enum('status', ['Negeri', 'Swasta'])->default('Swasta');
            $table->string('accreditation', 5)->nullable();
            $table->foreignId('foundation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('province_id', 2)->nullable();
            $table->string('city_id', 4)->nullable();
            $table->string('district_id', 7)->nullable();
            $table->string('village_id', 10)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('website', 150)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('headmaster_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('days', function (Blueprint $table) {
            $table->tinyInteger('id')->primary();
            $table->string('name', 20);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->string('type', 20)->default('string');
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('prefix', 20)->nullable();
            $table->string('format', 100)->nullable();
            $table->integer('last_number')->default(0);
            $table->enum('reset_period', ['never', 'year', 'month'])->default('never');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('days');
        Schema::dropIfExists('schools');
        Schema::dropIfExists('foundations');
    }
};