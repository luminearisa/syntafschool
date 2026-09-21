<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('category', ['Ringan', 'Sedang', 'Berat'])->default('Ringan');
            $table->integer('point')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('achievement_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('level', ['Sekolah', 'Kecamatan', 'Kabupaten', 'Provinsi', 'Nasional', 'Internasional'])->default('Sekolah');
            $table->timestamps();
        });

        Schema::create('extracurriculars', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('category', 50)->nullable();
            $table->string('instructor', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracurriculars');
        Schema::dropIfExists('achievement_types');
        Schema::dropIfExists('violation_types');
    }
};