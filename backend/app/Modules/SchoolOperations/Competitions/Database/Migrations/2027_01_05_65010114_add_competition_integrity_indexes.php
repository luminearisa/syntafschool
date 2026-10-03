<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Database-level idempotency and uniqueness guardrails for the core lifecycle. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_categories', function (Blueprint $table): void {
            $table->unique(['edition_id', 'code'], 'comp_categories_edition_code_uq');
        });
        Schema::table('competition_rounds', function (Blueprint $table): void {
            $table->unique(['category_id', 'sequence'], 'comp_rounds_category_sequence_uq');
        });
        Schema::table('competition_registrations', function (Blueprint $table): void {
            $table->unique('idempotency_key', 'comp_reg_idempotency_uq');
        });
        Schema::table('competition_registration_values', function (Blueprint $table): void {
            $table->unique(['registration_id', 'field_id'], 'comp_reg_value_field_uq');
        });
        Schema::table('competition_checkins', function (Blueprint $table): void {
            $table->unique('idempotency_key', 'comp_checkin_idempotency_uq');
        });
        Schema::table('competition_scorecards', function (Blueprint $table): void {
            $table->unique('idempotency_key', 'comp_scorecard_idempotency_uq');
        });
        Schema::table('competition_notifications', function (Blueprint $table): void {
            $table->unique('idempotency_key', 'comp_notification_idempotency_uq');
        });
        Schema::table('competition_waitlists', function (Blueprint $table): void {
            $table->unique('registration_id', 'comp_waitlist_registration_uq');
        });
        Schema::table('competition_award_recipients', function (Blueprint $table): void {
            $table->unique(['award_id', 'result_id'], 'comp_award_result_uq');
        });
    }

    public function down(): void
    {
        Schema::table('competition_award_recipients', fn (Blueprint $table) => $table->dropUnique('comp_award_result_uq'));
        Schema::table('competition_waitlists', fn (Blueprint $table) => $table->dropUnique('comp_waitlist_registration_uq'));
        Schema::table('competition_notifications', fn (Blueprint $table) => $table->dropUnique('comp_notification_idempotency_uq'));
        Schema::table('competition_scorecards', fn (Blueprint $table) => $table->dropUnique('comp_scorecard_idempotency_uq'));
        Schema::table('competition_checkins', fn (Blueprint $table) => $table->dropUnique('comp_checkin_idempotency_uq'));
        Schema::table('competition_registration_values', fn (Blueprint $table) => $table->dropUnique('comp_reg_value_field_uq'));
        Schema::table('competition_registrations', fn (Blueprint $table) => $table->dropUnique('comp_reg_idempotency_uq'));
        Schema::table('competition_rounds', fn (Blueprint $table) => $table->dropUnique('comp_rounds_category_sequence_uq'));
        Schema::table('competition_categories', fn (Blueprint $table) => $table->dropUnique('comp_categories_edition_code_uq'));
    }
};
