<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Pintu tunggal Profil 360°: memilih penyedia, memeriksa izin bagian, dan mencatat akses data sensitif. */
final class ProfileService
{
    /** Bagian yang aksesnya dicatat di log aktivitas (data sensitif). */
    private const AUDITED = ['gaji', 'konseling', 'kesehatan', 'keuangan', 'dompet', 'kepegawaian'];

    /** @var array<string, ProfileProvider> */
    private array $providers;

    public function __construct(StudentProfile $students, GuardianProfile $guardians, EmployeeProfile $employees)
    {
        $this->providers = [
            $students->type() => $students,
            $guardians->type() => $guardians,
            $employees->type() => $employees,
        ];
    }

    /** @return array<string, mixed> */
    public function overview(User $user, string $type, int $id): array
    {
        $provider = $this->provider($type);
        $entity = $provider->find($id);

        return [
            'type' => $type,
            'id' => $id,
            'header' => $provider->header($entity),
            'summary' => $provider->summary($entity, $user),
            'sections' => $provider->availableSections($user),
        ];
    }

    /** @return array<string, mixed> */
    public function section(User $user, string $type, int $id, string $key): array
    {
        $provider = $this->provider($type);
        $entity = $provider->find($id);

        $section = $provider->section($entity, $user, $key);
        if ($section === null) {
            throw new ModelNotFoundException('Bagian profil tidak dikenal.');
        }

        if (in_array($key, self::AUDITED, true)) {
            try {
                activity('profile')->causedBy($user)->performedOn($entity)
                    ->withProperties(['section' => $key])
                    ->log("Membuka bagian {$key} pada profil {$type}");
            } catch (\Throwable) {
                // Pencatatan tidak boleh menggagalkan tampilan.
            }
        }

        return $section;
    }

    private function provider(string $type): ProfileProvider
    {
        return $this->providers[$type] ?? throw new ModelNotFoundException('Jenis profil tidak dikenal.');
    }
}
