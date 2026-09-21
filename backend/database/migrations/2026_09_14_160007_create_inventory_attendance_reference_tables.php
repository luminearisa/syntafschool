<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('type', ['Aset', 'Habis Pakai'])->default('Aset');
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('symbol', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_statuses', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name', 50);
            $table->string('color', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('requires_attachment')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_statuses');
        Schema::dropIfExists('storage_locations');
        Schema::dropIfExists('units');
        Schema::dropIfExists('item_categories');
    }
};