<?php

namespace Database\Factories;

use App\Models\TahfizMemorizationRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class TahfizMemorizationRecordFactory extends Factory
{
    protected $model = TahfizMemorizationRecord::class;

    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'type' => 'hafalan_baru',
            'ayah_start' => 1,
            'ayah_end' => 3,
            'juz' => null,
            'page_start' => null,
            'page_end' => null,
            'line_count' => null,
            'duration_minutes' => null,
            'score' => null,
            'fluency' => null,
            'tajwid' => null,
            'makhraj' => null,
            'mistakes_count' => 0,
            'status' => 'submitted',
            'revision_due_date' => null,
            'teacher_note' => null,
            'participant_note' => null,
        ];
    }
}
