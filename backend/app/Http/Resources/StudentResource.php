<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'nis' => $this->nis,
            'nisn' => $this->nisn,
            'full_name' => $this->full_name,
            'nick_name' => $this->nick_name,
            'gender_id' => $this->gender_id,
            'religion_id' => $this->religion_id,
            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->toDateString(),
            'age' => $this->birth_date?->age,
            'blood_type' => $this->blood_type,
            'address' => $this->address,
            'province_id' => $this->province_id,
            'city_id' => $this->city_id,
            'district_id' => $this->district_id,
            'village_id' => $this->village_id,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_path' => $this->photo_path,
            'residence_type_id' => $this->residence_type_id,
            'transportation_id' => $this->transportation_id,
            'child_order' => $this->child_order,
            'sibling_count' => $this->sibling_count,
            'previous_school' => $this->previous_school,
            'admission_date' => $this->admission_date?->toDateString(),
            'status_id' => $this->status_id,
            'is_active' => $this->is_active,

            'gender' => $this->whenLoaded('gender', fn () => $this->ref($this->gender)),
            'religion' => $this->whenLoaded('religion', fn () => $this->ref($this->religion)),
            'status' => $this->whenLoaded('status', fn () => $this->ref($this->status)),
            'school' => $this->whenLoaded('school', fn () => $this->ref($this->school)),
            'province' => $this->whenLoaded('province', fn () => $this->ref($this->province)),
            'city' => $this->whenLoaded('city', fn () => $this->ref($this->city)),
            'district' => $this->whenLoaded('district', fn () => $this->ref($this->district)),
            'village' => $this->whenLoaded('village', fn () => $this->ref($this->village)),
            'residence_type' => $this->whenLoaded('residenceType', fn () => $this->ref($this->residenceType)),
            'transportation' => $this->whenLoaded('transportation', fn () => $this->ref($this->transportation)),

            'guardians' => $this->whenLoaded('guardians', fn () => $this->guardians->map(fn ($g) => [
                'id' => $g->id,
                'full_name' => $g->full_name,
                'phone' => $g->phone,
                'email' => $g->email,
                'occupation_id' => $g->occupation_id,
                'occupation' => $g->relationLoaded('occupation') && $g->occupation
                    ? ['id' => $g->occupation->id, 'name' => $g->occupation->name]
                    : null,
                'guardian_relation_id' => $g->pivot->guardian_relation_id,
                'is_primary' => (bool) $g->pivot->is_primary,
            ])->values()),

            'histories' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($h) => [
                'id' => $h->id,
                'academic_year_id' => $h->academic_year_id,
                'academic_year' => $h->relationLoaded('academicYear') && $h->academicYear
                    ? ['id' => $h->academicYear->id, 'name' => $h->academicYear->name]
                    : null,
                'school_class_id' => $h->school_class_id,
                'class_name' => $h->relationLoaded('schoolClass') && $h->schoolClass
                    ? $h->schoolClass->name
                    : null,
                'status' => $h->status,
                'note' => $h->note,
            ])->values()),

            'current_class' => $this->whenLoaded('classStudents', function () {
                $active = $this->classStudents->firstWhere('status', 'aktif');
                if (! $active || ! $active->relationLoaded('schoolClass')) {
                    return null;
                }

                return $active->schoolClass ? [
                    'class_student_id' => $active->id,
                    'id' => $active->schoolClass->id,
                    'name' => $active->schoolClass->name,
                    'code' => $active->schoolClass->code,
                ] : null;
            }),

            'guardians_count' => $this->whenCounted('guardians'),
            'has_account' => $this->whenLoaded('user', fn () => $this->user !== null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Bentuk ringkas relasi referensi: {id, name}.
     *
     * @return array{id: mixed, name: string}|null
     */
    private function ref(mixed $model): ?array
    {
        if (! $model) {
            return null;
        }

        return [
            'id' => $model->id,
            'name' => $model->name ?? $model->label ?? (string) $model->id,
        ];
    }
}
