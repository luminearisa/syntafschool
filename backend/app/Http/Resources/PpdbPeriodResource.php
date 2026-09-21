<?php

namespace App\Http\Resources;

use App\Models\PpdbPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Periode PPDB.
 *
 * `allowed_transitions` ikut dikirim supaya UI cukup menampilkan aksi yang
 * sah menurut `PpdbPeriod::TRANSITIONS` — aturan status tidak diduplikasi di
 * frontend.
 */
class PpdbPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PpdbPeriod $this */
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'slug' => $this->slug,
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? [
                'id' => $this->academicYear->id,
                'name' => $this->academicYear->name,
                'start_date' => $this->academicYear->start_date?->toDateString(),
                'end_date' => $this->academicYear->end_date?->toDateString(),
            ] : null),

            'public_title' => $this->public_title,
            'public_description' => $this->public_description,
            'banner_path' => $this->banner_path,
            'logo_path' => $this->logo_path,

            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'contact_address' => $this->contact_address,
            'faq' => $this->faq ?? [],

            'registration_start' => $this->registration_start?->toDateString(),
            'registration_end' => $this->registration_end?->toDateString(),
            'selection_announcement_date' => $this->selection_announcement_date?->toDateString(),
            're_registration_start' => $this->re_registration_start?->toDateString(),
            're_registration_end' => $this->re_registration_end?->toDateString(),

            'status' => $this->status,
            'allowed_transitions' => $this->allowedTransitions(),
            'is_registration_open' => $this->isRegistrationWindowOpen(),
            'description' => $this->description,

            'waves_count' => $this->whenCounted('waves'),
            'programs_count' => $this->whenCounted('programs'),
            'registrations_count' => $this->whenCounted('registrations'),
            'waves' => PpdbWaveResource::collection($this->whenLoaded('waves')),
            'programs' => PpdbProgramResource::collection($this->whenLoaded('programs')),

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
