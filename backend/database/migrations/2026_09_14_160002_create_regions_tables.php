<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->string('id', 2)->primary();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->string('id', 4)->primary();
            $table->string('province_id', 2);
            $table->string('name', 100);
            $table->string('type', 20)->nullable();
            $table->timestamps();
            $table->foreign('province_id')->references('id')->on('provinces')->cascadeOnUpdate()->restrictOnDelete();
            $table->index('name');
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->string('id', 7)->primary();
            $table->string('city_id', 4);
            $table->string('name', 100);
            $table->timestamps();
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnUpdate()->restrictOnDelete();
            $table->index('name');
        });

        Schema::create('villages', function (Blueprint $table) {
            $table->string('id', 10)->primary();
            $table->string('district_id', 7);
            $table->string('name', 100);
            $table->string('postal_code', 10)->nullable();
            $table->timestamps();
            $table->foreign('district_id')->references('id')->on('districts')->cascadeOnUpdate()->restrictOnDelete();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
    }
};