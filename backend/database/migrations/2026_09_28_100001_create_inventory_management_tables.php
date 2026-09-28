<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name', 160);
            $table->foreignId('item_category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('storage_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->string('serial_number', 100)->nullable();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->decimal('minimum_quantity', 14, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('condition', 30)->default('good');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_category_id', 'is_active']);
            $table->index(['storage_location_id', 'is_active']);
            $table->index('name');
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('movement_type', 20);
            $table->decimal('quantity', 14, 2);
            $table->decimal('quantity_before', 14, 2);
            $table->decimal('quantity_after', 14, 2);
            $table->string('reason', 255);
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['inventory_item_id', 'id']);
            $table->index(['movement_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
    }
};