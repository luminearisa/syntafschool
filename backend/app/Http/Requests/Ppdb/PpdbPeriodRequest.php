<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Periode PPDB.
 *
 * `code` dan `slug` boleh dikosongkan: nilainya dibangkitkan oleh
 * `PpdbPeriodService` dan dijamin unik. Bila diisi dan sudah dipakai,
 * validasi menolaknya secara eksplisit.
 */
class PpdbPeriodRequest extends FormRequest
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
        $period = $this->route('period');

        return [
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('ppdb_periods', 'code')->ignore($period?->id)],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'slug' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('ppdb_periods', 'slug')->ignore($period?->id),
            ],

            'public_title' => ['nullable', 'string', 'max:200'],
            'public_description' => ['nullable', 'string'],
            'banner_path' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],

            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'contact_address' => ['nullable', 'string'],

            'faq' => ['nullable', 'array'],
            'faq.*.question' => ['required_with:faq', 'string', 'max:200'],
            'faq.*.answer' => ['required_with:faq', 'string', 'max:2000'],

            'registration_start' => ['nullable', 'date'],
            'registration_end' => ['nullable', 'date', 'after_or_equal:registration_start'],
            'selection_announcement_date' => ['nullable', 'date'],
            're_registration_start' => ['nullable', 'date'],
            're_registration_end' => ['nullable', 'date', 'after_or_equal:re_registration_start'],

            'status' => ['nullable', 'string', Rule::in(PpdbPeriod::STATUSES)],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'academic_year_id' => 'tahun ajaran',
            'code' => 'kode',
            'slug' => 'slug',
            'registration_start' => 'tanggal mulai pendaftaran',
            'registration_end' => 'tanggal akhir pendaftaran',
            're_registration_start' => 'tanggal mulai daftar ulang',
            're_registration_end' => 'tanggal akhir daftar ulang',
            'selection_announcement_date' => 'tanggal pengumuman seleksi',
            'public_title' => 'judul halaman publik',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama periode wajib diisi.',
            'code.unique' => 'Kode periode sudah dipakai. Gunakan kode lain.',
            'slug.unique' => 'Slug sudah dipakai. Halaman publik tidak boleh memakai alamat yang sama.',
            'slug.regex' => 'Slug hanya boleh berisi huruf kecil, angka, dan tanda hubung.',
            'registration_end.after_or_equal' => 'Akhir pendaftaran tidak boleh mendahului awal pendaftaran.',
            're_registration_end.after_or_equal' => 'Akhir daftar ulang tidak boleh mendahului awal daftar ulang.',
            'status.in' => 'Status periode tidak dikenali.',
            'faq.*.question.required_with' => 'Pertanyaan FAQ wajib diisi.',
            'faq.*.answer.required_with' => 'Jawaban FAQ wajib diisi.',
        ];
    }
}
