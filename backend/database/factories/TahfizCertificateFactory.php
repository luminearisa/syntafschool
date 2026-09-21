<?php

namespace Database\Factories;

use App\Models\TahfizCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TahfizCertificateFactory extends Factory
{
    protected $model = TahfizCertificate::class;

    public function definition(): array
    {
        return [
            'certificate_number' => 'CERT-TEST-'.$this->faker->unique()->numerify('#####'),
            'achievement_type' => 'hafal_1_juz',
            'juz_count' => 1,
            'title' => 'Sertifikat Tahfiz',
            'description' => null,
            'issue_date' => now()->toDateString(),
            'signature_name' => 'Kepala Madrasah',
            // Dibangkitkan di sini, bukan diambil dari input klien.
            'qr_token' => Str::random(64),
            'status' => 'issued',
            'revoked_at' => null,
            'revoke_reason' => null,
        ];
    }
}
