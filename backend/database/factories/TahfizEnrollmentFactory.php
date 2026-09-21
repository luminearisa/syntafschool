<?php

namespace Database\Factories;

use App\Models\TahfizEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TahfizEnrollmentFactory extends Factory
{
    protected $model = TahfizEnrollment::class;

    public function definition(): array
    {
        return [
            'code' => 'ENR-TEST-'.$this->faker->unique()->numerify('#####'),
            'status' => 'registered',
            'enrolled_at' => now()->toDateString(),
            'start_date' => null,
            'expected_end_date' => null,
            'completed_at' => null,
            'starting_juz' => null,
            'target_note' => null,
            'notes' => null,
        ];
    }
}
