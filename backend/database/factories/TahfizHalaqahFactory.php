<?php

namespace Database\Factories;

use App\Models\TahfizHalaqah;
use Illuminate\Database\Eloquent\Factories\Factory;

class TahfizHalaqahFactory extends Factory
{
    protected $model = TahfizHalaqah::class;

    public function definition(): array
    {
        return [
            'name' => 'Halaqah '.$this->faker->word(),
            'code' => 'HLQ-TEST-'.$this->faker->unique()->numerify('#####'),
            'capacity' => 15,
            'schedule_summary' => 'Senin–Kamis 15.00–16.30',
            'status' => 'active',
            'notes' => null,
        ];
    }
}
