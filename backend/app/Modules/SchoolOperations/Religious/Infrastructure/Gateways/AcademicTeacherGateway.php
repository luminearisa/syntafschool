<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\TeacherGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Guru & kualifikasi keagamaan. Guru tetap milik Academic/HRD.
 */
class AcademicTeacherGateway implements TeacherGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\LMS\\Domain\\Contracts\\TeacherProviderInterface')
            ?? $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\TeacherProviderInterface');
    }

    public function snapshot(int $teacherId): ?array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['find', 'info', 'teacher'] as $method) {
                    if (method_exists($provider, $method)) {
                        $found = $provider->{$method}($teacherId);
                        if (is_array($found)) {
                            return $found;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $table = $this->firstTable(['teachers', 'ac_teachers']);
        if ($table === null) {
            return null;
        }

        $row = $this->row($table, $teacherId);

        return $row ? [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'] ?? ($row['name'] ?? null),
            'employee_id' => $row['employee_id'] ?? null,
            'user_id' => $row['user_id'] ?? null,
            'specialization' => $row['specialization'] ?? null,
            'active' => (bool) ($row['is_active'] ?? true),
            'source' => $table,
        ] : null;
    }

    public function religiousSpecialists(array $filters = []): array
    {
        $table = $this->firstTable(['teachers', 'ac_teachers']);
        if ($table === null) {
            return [];
        }

        $columns = $this->columns($table);
        $query = DB::table($table);

        $specialization = $this->pick($columns, ['religious_specialization', 'specialization']);
        if ($specialization && ! empty($filters['specialization'])) {
            $query->where($specialization, 'like', '%'.$filters['specialization'].'%');
        }

        // Guru agama dikenali dari spesialisasi/bidang yang mengandung kata kunci keagamaan.
        if ($specialization && empty($filters['specialization'])) {
            $query->where(function ($q) use ($specialization): void {
                foreach (['agama', 'quran', "qur'an", 'tahfiz', 'tahfidz', 'fiqh', 'aqidah', 'arab', 'islam'] as $kw) {
                    $q->orWhere($specialization, 'like', '%'.$kw.'%');
                }
            });
        }

        return $this->mapIds($query->limit((int) ($filters['limit'] ?? 100))->get()->all(),
            fn (array $row): ?array => $this->snapshot((int) $row['id']));
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->firstTable(['teachers', 'ac_teachers']) !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Sumber data guru tidak tersedia pada instalasi ini.';
    }
}
