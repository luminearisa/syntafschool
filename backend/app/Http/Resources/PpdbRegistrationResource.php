<?php

namespace App\Http\Resources;

use App\Models\PpdbRegistration;
use App\Services\Ppdb\PpdbParentAccountService;
use App\Services\Ppdb\PpdbRegistrationQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baris pendaftaran PPDB untuk daftar (portal & admin).
 *
 * `row()` dipakai bersama oleh daftar portal, dashboard, dan daftar admin agar
 * satu pendaftaran punya satu bentuk data.
 *
 * @property PpdbRegistration $resource
 */
class PpdbRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::row($this->resource);
    }

    /**
     * Kolom `parent` hanya ikut ketika relasi akun orang tua dimuat (daftar
     * admin); daftar portal memuatnya sendiri karena itu akun pemilik data.
     *
     * @return array<string, mixed>
     */
    public static function row(PpdbRegistration $registration): array
    {
        $applicant = $registration->applicant;

        $data = [
            'id' => $registration->id,
            'registration_id' => $registration->id,
            'registration_number' => $registration->registration_number,
            'status' => $registration->status,
            'status_label' => PpdbRegistrationQueryService::statusLabel((string) $registration->status),
            'payment_status' => $registration->payment_status,
            'payment_status_label' => PpdbRegistrationQueryService::paymentLabel((string) $registration->payment_status),
            'document_status' => $registration->document_status,
            'document_status_label' => PpdbRegistrationQueryService::documentLabel((string) $registration->document_status),
            'is_editable' => $registration->isEditable(),
            'revision_note' => $registration->revision_note,
            'notes' => $registration->notes,
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
            'verified_at' => $registration->verified_at?->toIso8601String(),
            'selection_result' => $registration->selection_result,
            'announced_at' => $registration->announced_at?->toIso8601String(),
            'completed_at' => $registration->completed_at?->toIso8601String(),
            'student_id' => $registration->student_id,
            'next_action' => PpdbRegistrationQueryService::nextAction((string) $registration->status),
            'applicant' => $applicant === null ? null : [
                'id' => $applicant->id,
                'full_name' => $applicant->full_name,
                'nickname' => $applicant->nickname,
                'gender_id' => $applicant->gender_id,
                'birth_date' => $applicant->birth_date?->toDateString(),
            ],
            'program' => self::ref($registration->program),
            'wave' => self::ref($registration->wave),
            'period' => self::ref($registration->period) + ['slug' => $registration->period?->slug],
            'applicant_id' => $registration->applicant_id,
            'parent_account_id' => $registration->parent_account_id,
            'period_id' => $registration->period_id,
            'program_id' => $registration->program_id,
            'wave_id' => $registration->wave_id,
            'created_at' => $registration->created_at?->toIso8601String(),
            'updated_at' => $registration->updated_at?->toIso8601String(),
            // Hanya tautan API yang dikirim di sini. Path SPA bukan wewenang
            // resource: router frontend yang memilikinya, dan rute SPA berubah
            // tanpa bisa diikuti backend.
            'links' => [
                'api_detail' => '/api/v1/ppdb/portal/registrations/'.$registration->id,
                'payment_api' => '/api/v1/ppdb/portal/registrations/'.$registration->id.'/payment',
            ],
        ];

        if ($registration->relationLoaded('parentAccount')) {
            $account = $registration->parentAccount;
            $data['parent'] = $account === null ? null : [
                'parent_account_id' => $account->id,
                'user_id' => $account->user_id,
                'name' => $account->user?->name,
                'email' => $account->user?->email,
                'phone' => $account->user?->phone,
                'account_status' => $account->account_status,
                'account_status_label' => PpdbParentAccountService::statusLabel((string) $account->account_status),
            ];
        }

        return $data;
    }

    /**
     * @return array{id: mixed, code: mixed, name: mixed}
     */
    private static function ref(mixed $model): array
    {
        return [
            'id' => $model?->id,
            'code' => $model?->code,
            'name' => $model?->name,
        ];
    }
}
