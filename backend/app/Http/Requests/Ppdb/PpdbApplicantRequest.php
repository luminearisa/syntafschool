<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbApplicant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Data calon peserta didik (anak) pada portal & admin.
 *
 * NIK/NISN boleh kosong, tetapi tidak boleh kembar secara global — satu anak
 * hanya punya satu identitas.
 */
class PpdbApplicantRequest extends FormRequest
{
    /**
     * Kolom yang TIDAK boleh ditulis dari portal: catatan internal panitia,
     * status arsip, dan kepemilikan data (akun/orang tua/wali). Keys ini tetap
     * diterima pada rute admin (`ppdb.applicants.update`).
     */
    public const PORTAL_FORBIDDEN = [
        'notes', 'status', 'guardian_id', 'parent_account_id', 'photo_path',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $applicant = $this->route('applicant');

        $rules = self::nestedRules('', $applicant instanceof PpdbApplicant ? (int) $applicant->id : null);

        // Status arsip hanya panitia; portal tidak pernah mengubahnya.
        if ($this->isAdminRoute()) {
            $rules['status'] = ['sometimes', 'string', Rule::in(PpdbApplicant::STATUSES)];
        }

        return $rules;
    }

    public function isAdminRoute(): bool
    {
        return $this->routeIs('ppdb.applicants.update');
    }

    /**
     * Aturan biodata calon; $prefix dipakai saat disarangkan pada request lain
     * (mis. `applicant.` pada autosave pendaftaran).
     *
     * @return array<string, mixed>
     */
    public static function nestedRules(string $prefix = '', ?int $ignoreId = null): array
    {
        $key = fn (string $column): string => $prefix.$column;

        return [
            $key('full_name') => ['required', 'string', 'max:150'],
            $key('nickname') => ['nullable', 'string', 'max:50'],
            $key('gender_id') => ['nullable', 'integer', 'exists:genders,id'],
            $key('birth_place') => ['nullable', 'string', 'max:100'],
            $key('birth_date') => ['nullable', 'date', 'before_or_equal:'.now()->toDateString()],
            $key('nik') => [
                'nullable', 'string', 'regex:/^\d{8,16}$/',
                Rule::unique('ppdb_applicants', 'nik')->whereNull('deleted_at')->ignore($ignoreId),
            ],
            $key('nisn') => [
                'nullable', 'string', 'regex:/^\d{8,16}$/',
                Rule::unique('ppdb_applicants', 'nisn')->whereNull('deleted_at')->ignore($ignoreId),
            ],
            $key('religion_id') => ['nullable', 'integer', 'exists:religions,id'],
            $key('address') => ['nullable', 'string', 'max:1000'],
            $key('province_id') => ['nullable', 'string', 'max:2'],
            $key('city_id') => ['nullable', 'string', 'max:4'],
            $key('district_id') => ['nullable', 'string', 'max:7'],
            $key('village_id') => ['nullable', 'string', 'max:10'],
            $key('postal_code') => ['nullable', 'string', 'max:10'],
            $key('previous_school') => ['nullable', 'string', 'max:150'],
            $key('previous_school_npsn') => ['nullable', 'string', 'max:20'],
            $key('target_program_id') => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            $key('notes') => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Kolom yang boleh diisi calon lewat portal (tanpa status & catatan admin).
     *
     * @return array<string, mixed>
     */
    public static function portalRules(?int $ignoreId = null): array
    {
        $rules = self::nestedRules('', $ignoreId);

        foreach (self::PORTAL_FORBIDDEN as $column) {
            unset($rules[$column]);
        }

        return $rules;
    }

    /**
     * Saring data calon yang datang dari portal: kolom panitia dibuang supaya
     * autosave tidak bisa menulis `notes`/`status` atau memindahkan kepemilikan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function portalOnly(array $data): array
    {
        return array_diff_key($data, array_flip(self::PORTAL_FORBIDDEN));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'nama lengkap calon',
            'nik' => 'NIK',
            'nisn' => 'NISN',
            'birth_date' => 'tanggal lahir',
            'target_program_id' => 'program tujuan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nik.regex' => 'NIK hanya boleh berisi angka (8–16 digit).',
            'nik.unique' => 'NIK ini sudah dipakai calon lain. Periksa kembali data Anda.',
            'nisn.regex' => 'NISN hanya boleh berisi angka (8–16 digit).',
            'nisn.unique' => 'NISN ini sudah dipakai calon lain. Periksa kembali data Anda.',
            'birth_date.before_or' => 'Tanggal lahir tidak boleh di masa depan.',
        ];
    }
}
