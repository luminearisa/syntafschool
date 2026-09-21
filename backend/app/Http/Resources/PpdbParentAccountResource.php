<?php

namespace App\Http\Resources;

use App\Models\PpdbParentAccount;
use App\Services\Ppdb\PpdbParentAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun orang tua PPDB.
 *
 * Identitas dibaca dari relasi `user` (nama, email, telepon) dan `guardian`
 * (detail wali) — TIDAK ada salinan kolom identitas di tabel akun, jadi
 * resource inilah tempatnya menggabungkan keduanya.
 *
 * `applicant_count` & `registration_count` ikut di bentuk dasar karena aturan
 * "satu akun untuk banyak anak" harus terbaca di daftar mana pun akun ini
 * muncul. Keduanya memakai hasil `withCount()` bila ada, jadi daftar panjang
 * tidak menimbulkan satu query per baris.
 *
 * @property PpdbParentAccount $resource
 */
class PpdbParentAccountResource extends JsonResource
{
    public function __construct(PpdbParentAccount $resource, private readonly bool $withPrivateNotes = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = self::row($this->resource);

        if ($this->withPrivateNotes) {
            // Catatan panitia hanya untuk layar admin (`ppdb.account.manage`).
            $data['notes'] = $this->resource->notes;
        }

        if ($this->resource->relationLoaded('applicants') && $this->resource->applicants->isNotEmpty()) {
            $data['applicants'] = $this->resource->applicants
                ->map(fn (mixed $applicant): array => PpdbApplicantResource::row($applicant))
                ->all();
        }

        return $data;
    }

    /**
     * Bentuk dasar akun orang tua (dipakai juga oleh portal & agregasi pendaftaran).
     *
     * @return array<string, mixed>
     */
    public static function row(PpdbParentAccount $account): array
    {
        $user = $account->user;
        $guardian = $account->guardian;

        return [
            // `id` sama dengan `parent_account_id`: seluruh resource project
            // memakai `id` sebagai kunci baris, dan tanpa kunci itu halaman
            // panitia memanggil `/parent-accounts/undefined`.
            'id' => $account->id,
            'parent_account_id' => $account->id,
            'user_id' => $account->user_id,
            'guardian_id' => $account->guardian_id,
            'name' => $user?->name,
            'email' => $user?->email,
            'phone' => $user?->phone,
            'address' => $guardian?->address,
            'gender_id' => $guardian?->gender_id,
            'religion_id' => $guardian?->religion_id,
            'occupation_id' => $guardian?->occupation_id,
            'education_background_id' => $guardian?->education_background_id,
            'income_range_id' => $guardian?->income_range_id,
            'account_status' => $account->account_status,
            'account_status_label' => PpdbParentAccountService::statusLabel((string) $account->account_status),
            'can_transact' => $account->canTransact(),
            'verified_at' => $account->verified_at?->toIso8601String(),
            'last_activity_at' => $account->last_activity_at?->toIso8601String(),
            'applicant_count' => (int) ($account->applicants_count ?? $account->applicants()->count()),
            'registration_count' => (int) ($account->registrations_count ?? $account->registrations()->count()),
            'one_account_for_all_children' => true,
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }
}
