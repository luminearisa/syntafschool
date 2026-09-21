<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4 — Kalender Akademik (kolom tambahan).
 *
 * Melengkapi tabel yang sudah dibuat `2026_09_18_100002_create_academic_calendar_tables`:
 *  - `event_types.is_holiday`      — jenis event ini secara default hari libur.
 *  - `academic_events.is_holiday`  — event ini meniadakan hari sekolah.
 *  - `academic_events.academic_year_id` — tautan opsional ke tahun ajaran,
 *    dipakai untuk memastikan semester yang dipilih memang milik tahun ajaran itu.
 *  - unique index (semester_id, education_level_id, date) pada `effective_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->boolean('is_holiday')->default(false)->after('color');
        });

        Schema::table('academic_events', function (Blueprint $table) {
            $table->boolean('is_holiday')->default(false)->after('location');
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('semester_id')
                ->constrained()
                ->nullOnDelete();
        });

        // Unique index penjaga (semester, jenjang, tanggal). Migrasi fondasi sudah
        // membuat `effective_day_uniq`; tambahkan hanya bila belum ada agar tidak
        // menggandakan indeks yang sama.
        if (! $this->hasEffectiveDayUniqueIndex()) {
            Schema::table('effective_days', function (Blueprint $table) {
                $table->unique(
                    ['semester_id', 'education_level_id', 'date'],
                    'effective_days_semester_level_date_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndexNamed('effective_days', 'effective_days_semester_level_date_unique')) {
            Schema::table('effective_days', function (Blueprint $table) {
                $table->dropUnique('effective_days_semester_level_date_unique');
            });
        }

        Schema::table('academic_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn('is_holiday');
        });

        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn('is_holiday');
        });
    }

    /**
     * Apakah sudah ada unique index pada (semester_id, education_level_id, date)?
     */
    protected function hasEffectiveDayUniqueIndex(): bool
    {
        if (! Schema::hasTable('effective_days')) {
            return false;
        }

        $wanted = ['semester_id', 'education_level_id', 'date'];

        foreach (Schema::getIndexes('effective_days') as $index) {
            $columns = $index['columns'] ?? [];

            if (($index['unique'] ?? false) && $columns === $wanted) {
                return true;
            }
        }

        return false;
    }

    protected function hasIndexNamed(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
