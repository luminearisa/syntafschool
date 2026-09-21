<?php

namespace App\Http\Requests\Ppdb;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pengajuan sanggahan (masa sanggah) oleh orang tua atas hasil seleksi.
 *
 * Yang divalidasi di sini hanya bentuk berkas; **kelayakan mengajukan**
 * (milik sendiri, jendela `appeal_start`–`appeal_end`, hasil `failed`/
 * `waitlisted`, belum ada sanggahan pending) dijaga `PpdbAppealService` karena
 * butuh kondisi pendaftaran & periode.
 */
class PpdbAppealRequest extends FormRequest
{
    /** Ekstensi yang diterima sebagai lampiran alasan. */
    public const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

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
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:'.implode(',', self::ALLOWED_MIMES), 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan sanggahan wajib ditulis.',
            'reason.min' => 'Alasan sanggahan minimal :min karakter agar dapat ditelaah panitia.',
            'reason.max' => 'Alasan sanggahan maksimal :max karakter.',
            'attachment.mimes' => 'Lampiran harus berupa berkas PDF, JPG, atau PNG.',
            'attachment.max' => 'Lampiran maksimal 5 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan sanggahan',
            'attachment' => 'lampiran',
        ];
    }
}
