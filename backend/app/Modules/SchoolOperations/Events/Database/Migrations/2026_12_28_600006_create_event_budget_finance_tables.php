<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600006_create_event_budget_finance_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_budgets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('budget_code', 50)->unique();
            $t->string('title', 200);
            $t->unsignedBigInteger('fiscal_year_id')->nullable()->index();
            $t->decimal('total_planned')->default(0);
            $t->decimal('total_committed')->default(0);
            $t->decimal('total_actual')->default(0);
            $t->decimal('total_remaining')->default(0);
            $t->string('currency', 10)->default('IDR');
            $t->string('funding_source', 40)->default('SCHOOL');
            $t->decimal('variance_threshold')->nullable();
            $t->decimal('variance_percent')->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->unsignedBigInteger('submitted_by')->nullable()->index();
            $t->dateTime('submitted_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable()->index();
            $t->dateTime('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->string('finance_reference', 80)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_budget_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('budget_id')->index()->constrained('event_budgets')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('category', 40)->index();
            $t->string('subcategory', 60)->nullable();
            $t->string('description', 255);
            $t->decimal('quantity')->default(1);
            $t->string('unit', 30)->nullable();
            $t->decimal('unit_cost')->default(0);
            $t->decimal('planned_amount')->default(0);
            $t->decimal('committed_amount')->default(0);
            $t->decimal('actual_amount')->default(0);
            $t->decimal('remaining_amount')->nullable();
            $t->string('vendor_name', 200)->nullable();
            $t->unsignedBigInteger('vendor_id')->nullable()->index();
            $t->boolean('procurement_required')->default(false);
            $t->unsignedBigInteger('procurement_request_id')->nullable();
            $t->string('account_code', 60)->nullable();
            $t->integer('sort_order')->default(0);
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_costs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('budget_id')->nullable()->index();
            $t->unsignedBigInteger('budget_item_id')->nullable()->index();
            $t->string('cost_code', 50)->nullable();
            $t->string('category', 40)->index();
            $t->string('description', 255);
            $t->decimal('amount')->default(0);
            $t->date('cost_date')->index();
            $t->string('cost_type', 20)->index()->default('ACTUAL');
            $t->string('vendor_name', 200)->nullable();
            $t->string('invoice_number', 80)->nullable();
            $t->string('payment_reference', 80)->nullable();
            $t->unsignedBigInteger('finance_posting_id')->nullable();
            $t->unsignedBigInteger('proof_document_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable()->index();
            $t->string('status', 20)->index()->default('RECORDED');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_sponsors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('sponsor_name', 200);
            $t->string('sponsor_type', 30)->default('COMPANY');
            $t->string('contact_person', 150)->nullable();
            $t->string('contact_email', 150)->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->unsignedBigInteger('package_id')->nullable()->index();
            $t->string('package_name', 150)->nullable();
            $t->decimal('commitment_amount')->nullable();
            $t->decimal('received_amount')->default(0);
            $t->string('commitment_type', 20)->default('CASH');
            $t->json('benefits')->nullable();
            $t->unsignedBigInteger('agreement_document_id')->nullable();
            $t->string('status', 30)->index()->default('PROSPECT');
            $t->dateTime('confirmed_at')->nullable();
            $t->string('fundraising_reference', 80)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_sponsorship_packages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->decimal('price')->default(0);
            $t->integer('quota')->nullable();
            $t->integer('taken')->default(0);
            $t->json('benefits')->nullable();
            $t->boolean('is_active')->index()->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_vendors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('vendor_id')->nullable()->index();
            $t->string('vendor_name', 200);
            $t->string('vendor_type', 40)->index();
            $t->text('service_description')->nullable();
            $t->string('contact_person', 150)->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->string('contract_number', 80)->nullable();
            $t->unsignedBigInteger('contract_document_id')->nullable();
            $t->decimal('contract_value')->nullable();
            $t->decimal('paid_amount')->default(0);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->integer('rating')->nullable();
            $t->unsignedBigInteger('procurement_request_id')->nullable();
            $t->string('status', 30)->index()->default('PENDING');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_vendors');
        Schema::dropIfExists('event_sponsorship_packages');
        Schema::dropIfExists('event_sponsors');
        Schema::dropIfExists('event_costs');
        Schema::dropIfExists('event_budget_items');
        Schema::dropIfExists('event_budgets');
    }
};
