<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'member_number' => $this->member_number,

            'display_name' => $this->display_name,
            'linked_type' => $this->linkedType(),

            'name' => $this->name,
            'gender_id' => $this->gender_id,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'identity_number' => $this->identity_number,
            'photo_path' => $this->photo_path,

            'membership_type_id' => $this->membership_type_id,
            'registered_at' => $this->registered_at?->toDateString(),
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'status' => $this->status,
            'is_expired' => $this->isExpired(),
            'is_within_validity' => $this->isWithinValidity(),
            'notes' => $this->notes,

            'library' => $this->whenLoaded('library', fn () => $this->library ? [
                'id' => $this->library->id,
                'code' => $this->library->code,
                'name' => $this->library->name,
            ] : null),

            'membership_type' => $this->whenLoaded('membershipType', fn () => $this->membershipType ? [
                'id' => $this->membershipType->id,
                'code' => $this->membershipType->code,
                'name' => $this->membershipType->name,
                'max_loan' => $this->membershipType->max_loan,
                'loan_duration_days' => $this->membershipType->loan_duration_days,
            ] : null),

            // Entitas tertaut — identitas sumber, bukan salinan.
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'nis' => $this->student->nis,
                'full_name' => $this->student->full_name,
            ] : null),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'nip' => $this->employee->nip,
                'full_name' => $this->employee->full_name,
            ] : null),
            'guardian' => $this->whenLoaded('guardian', fn () => $this->guardian ? [
                'id' => $this->guardian->id,
                'full_name' => $this->guardian->full_name,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),

            'cards' => LibraryMemberCardResource::collection($this->whenLoaded('cards')),
            'active_card' => $this->whenLoaded('cards', fn () => $this->cards->firstWhere('status', 'active') !== null
                ? new LibraryMemberCardResource($this->cards->firstWhere('status', 'active'))
                : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}