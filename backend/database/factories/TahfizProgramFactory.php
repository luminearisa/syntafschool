<?php

namespace Database\Factories;

use App\Models\TahfizProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory konfigurasi program Tahfiz untuk test.
 */
class TahfizProgramFactory extends Factory
{
    protected $model = TahfizProgram::class;

    public function definition(): array
    {
        return [
            'name' => 'Program Test '.$this->faker->word(),
            'code' => 'PRG-TEST-'.$this->faker->unique()->numerify('#####'),
            'description' => null,
            'participant_type' => 'public',
            'duration_value' => 12,
            'duration_unit' => 'month',
            'target_type' => 'juz',
            'target_value' => '30',
            'minimum_attendance' => 80,
            'assessment_scheme' => null,
            'requires_placement_test' => false,
            'self_registration' => true,
            'status' => 'active',
        ];
    }
}
