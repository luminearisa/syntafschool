<?php

namespace Database\Factories;

use App\Models\TahfizExam;
use Illuminate\Database\Eloquent\Factories\Factory;

class TahfizExamFactory extends Factory
{
    protected $model = TahfizExam::class;

    public function definition(): array
    {
        return [
            'code' => 'EXM-TEST-'.$this->faker->unique()->numerify('#####'),
            'name' => 'Ujian '.$this->faker->word(),
            'type' => 'acak_ayat',
            'exam_date' => now()->addDays(7)->toDateString(),
            'start_time' => '08:00',
            'duration_minutes' => 60,
            'max_score' => 100,
            'passing_score' => 75,
            'description' => null,
            'status' => 'draft',
        ];
    }
}
