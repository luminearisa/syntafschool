<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\User;
use App\Modules\UKS\Models\UksHealthProfile;
use App\Modules\UKS\Models\UksImmunization;
use App\Modules\UKS\Models\UksScreening;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Rekam kesehatan siswa: profil, pemeriksaan berkala, dan imunisasi.
 *
 * IMT dihitung server (berat / (tinggi m)²) supaya angka yang tampil di rapor
 * kesehatan tidak bergantung pada hitungan klien.
 */
class HealthRecordService
{
    public function __construct(private readonly UksAccessService $access)
    {
    }

    /** Ringkasan rekam seorang siswa: profil + riwayat ringkas. */
    public function studentRecord(User $actor, int $studentId): array
    {
        $this->access->assertCanSeeStudent($actor, $studentId);

        $profile = UksHealthProfile::query()->where('student_id', $studentId)->first();

        return [
            'profile' => $profile,
            'screenings' => UksScreening::query()
                ->where('student_id', $studentId)
                ->orderByDesc('screened_at')->limit(20)->get(),
            'immunizations' => UksImmunization::query()
                ->where('student_id', $studentId)
                ->orderByDesc('given_at')->limit(30)->get(),
            'visits_count' => DB::table('uks_visits')->where('student_id', $studentId)->count(),
            'incidents_count' => DB::table('uks_incidents')->where('student_id', $studentId)->count(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveProfile(int $studentId, array $data, User $actor): UksHealthProfile
    {
        $this->access->assertCanSeeStudent($actor, $studentId);

        $profile = UksHealthProfile::query()->updateOrCreate(
            ['student_id' => $studentId],
            array_intersect_key($data, array_flip([
                'blood_type', 'allergies', 'chronic_conditions', 'regular_medications',
                'special_needs', 'insurance_provider', 'insurance_number',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation', 'notes',
            ])) + ['updated_by' => $actor->id],
        );

        activity('uks')->causedBy($actor)->performedOn($profile)
            ->log('Profil kesehatan siswa diperbarui');

        return $profile;
    }

    /** @param array<string, mixed> $filters */
    public function listScreenings(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = UksScreening::query()->with(['student:id,full_name,nis', 'schoolClass:id,name']);
        $this->access->scopeStudents($query, $actor);

        foreach (['screening_type', 'result', 'student_id', 'school_class_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return $query->orderByDesc('screened_at')->orderByDesc('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function recordScreening(array $data, User $actor): UksScreening
    {
        $this->access->assertCanSeeStudent($actor, (int) $data['student_id']);

        $height = isset($data['height_cm']) ? (float) $data['height_cm'] : null;
        $weight = isset($data['weight_kg']) ? (float) $data['weight_kg'] : null;
        $bmi = ($height && $weight && $height > 0) ? round($weight / (($height / 100) ** 2), 2) : null;

        return UksScreening::query()->create([
            'student_id' => (int) $data['student_id'],
            'school_class_id' => $data['school_class_id']
                ?? DB::table('class_students')->where('student_id', $data['student_id'])->where('status', 'aktif')->orderByDesc('id')->value('school_class_id'),
            'academic_year_id' => $data['academic_year_id']
                ?? DB::table('academic_years')->where('is_active', true)->orderByDesc('id')->value('id'),
            'screening_type' => $data['screening_type'],
            'screened_at' => $data['screened_at'] ?? now()->toDateString(),
            'height_cm' => $height,
            'weight_kg' => $weight,
            'bmi' => $bmi,
            'vision_left' => $data['vision_left'] ?? null,
            'vision_right' => $data['vision_right'] ?? null,
            'dental_status' => $data['dental_status'] ?? null,
            'result' => $data['result'] ?? 'NORMAL',
            'findings' => $data['findings'] ?? null,
            'recommendation' => $data['recommendation'] ?? null,
            'screened_by' => $actor->id,
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function listImmunizations(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = UksImmunization::query()->with('student:id,full_name,nis');
        $this->access->scopeStudents($query, $actor);

        if (! empty($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }
        if (! empty($filters['due_soon'])) {
            $days = (int) config('uks.immunization_due_days', 14);
            $query->whereNotNull('next_due_at')->whereDate('next_due_at', '<=', now()->addDays($days)->toDateString());
        }

        return $query->orderByDesc('given_at')->orderByDesc('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function recordImmunization(array $data, User $actor): UksImmunization
    {
        $this->access->assertCanSeeStudent($actor, (int) $data['student_id']);

        return UksImmunization::query()->create([
            'student_id' => (int) $data['student_id'],
            'vaccine_name' => $data['vaccine_name'],
            'dose_number' => (int) ($data['dose_number'] ?? 1),
            'given_at' => $data['given_at'] ?? now()->toDateString(),
            'batch_no' => $data['batch_no'] ?? null,
            'provider' => $data['provider'] ?? null,
            'next_due_at' => $data['next_due_at'] ?? null,
            'reaction' => $data['reaction'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $actor->id,
        ]);
    }
}
