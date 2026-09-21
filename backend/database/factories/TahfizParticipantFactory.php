<?php

namespace Database\Factories;

use App\Models\TahfizParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TahfizParticipantFactory extends Factory
{
    protected $model = TahfizParticipant::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'participant_type' => 'public',
            'name' => $this->faker->name(),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'birth_date' => $this->faker->date(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'address' => null,
            'photo_path' => null,
            'notes' => null,
            'status' => 'active',
        ];
    }
}
