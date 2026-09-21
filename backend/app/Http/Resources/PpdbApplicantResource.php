<?php

namespace App\Http\Resources;

use App\Models\PpdbApplicant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Calon peserta didik (anak) milik satu akun orang tua.
 *
 * `row()` adalah SATU-SATUNYA bentuk calon peserta di API — endpoint portal/admin
 * maupun agregasi `PpdbRegistrationQueryService` memakainya supaya bentuk data
 * tidak dirawat di dua tempat.
 *
 * @property PpdbApplicant $resource
 */
class PpdbApplicantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = self::row($this->resource);
        $data['age_label'] = $data['age_years'] === null ? null : $data['age_years'].' tahun';

        // Ikut terbawa hanya bila query-nya memang memuat relasi — sama seperti
        // resource lain di modul ini, jadi kunci yang dijanjikan tidak pernah
        // hilang di endpoint daftar.
        if ($this->resource->relationLoaded('registrations')) {
            $data['registrations'] = $this->resource->getRelation('registrations')
                ->map(fn (mixed $registration): array => PpdbRegistrationResource::row($registration))
                ->values()
                ->all();
        }

        if ($this->resource->relationLoaded('parentAccount')) {
            $data['parent_name'] = $this->resource->parentAccount?->user?->name;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(PpdbApplicant $applicant): array
    {
        return [
            'id' => $applicant->id,
            'parent_account_id' => $applicant->parent_account_id,
            'guardian_id' => $applicant->guardian_id,
            'full_name' => $applicant->full_name,
            'nickname' => $applicant->nickname,
            'gender_id' => $applicant->gender_id,
            'gender' => $applicant->gender?->name,
            'birth_place' => $applicant->birth_place,
            'birth_date' => $applicant->birth_date?->toDateString(),
            'age_years' => $applicant->birth_date === null ? null : (int) $applicant->birth_date->diffInYears(now()),
            'nik' => $applicant->nik,
            'nisn' => $applicant->nisn,
            'religion_id' => $applicant->religion_id,
            'religion' => $applicant->religion?->name,
            'address' => $applicant->address,
            'province_id' => $applicant->province_id,
            'city_id' => $applicant->city_id,
            'district_id' => $applicant->district_id,
            'village_id' => $applicant->village_id,
            'postal_code' => $applicant->postal_code,
            'previous_school' => $applicant->previous_school,
            'previous_school_npsn' => $applicant->previous_school_npsn,
            'target_program_id' => $applicant->target_program_id,
            'target_program' => $applicant->targetProgram?->name,
            'photo_path' => $applicant->photo_path,
            'status' => $applicant->status,
            'notes' => $applicant->notes,
            'created_at' => $applicant->created_at?->toIso8601String(),
            'updated_at' => $applicant->updated_at?->toIso8601String(),
        ];
    }
}
