<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penetapan hasil seleksi satu pendaftaran.
 *
 * Hanya tiga hasil yang menggerakkan mesin status (`selection` →
 * `passed|failed|waitlisted`); `announcement_id` opsional supaya riwayat menyebut
 * pengumuman yang jadi dasarnya.
 *
 * `quota_override_reason` dipakai penjaga kuota §27: penetapan `passed` di atas
 * batas kursi ditolak kecuali pengirim mengirim alasan eksplisit, yang kemudian
 * disimpan pada `ppdb_registrations.quota_override_reason`.
 */
class PpdbResultSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'result' => ['required', 'string', Rule::in([
                PpdbRegistration::STATUS_PASSED,
                PpdbRegistration::STATUS_FAILED,
                PpdbRegistration::STATUS_WAITLISTED,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
            'announcement_id' => ['nullable', 'integer', 'exists:ppdb_announcements,id'],
            'quota_override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'result.required' => 'Hasil seleksi wajib dipilih.',
            'result.in' => 'Hasil harus passed, failed, atau waitlisted.',
            'announcement_id.exists' => 'Pengumuman rujukan tidak ditemukan.',
            'quota_override_reason.min' => 'Alasan melewati kuota harus dijelaskan minimal :min karakter.',
            'quota_override_reason.max' => 'Alasan melewati kuota maksimal :max karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'result' => 'hasil',
            'note' => 'catatan',
            'announcement_id' => 'pengumuman',
            'quota_override_reason' => 'alasan melewati kuota',
        ];
    }
}
