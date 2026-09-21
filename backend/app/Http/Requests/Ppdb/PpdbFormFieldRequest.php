<?php

namespace App\Http\Requests\Ppdb;

use App\Models\PpdbFormField;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Field form pendaftaran (form builder).
 *
 * `field_key` adalah kunci jawaban yang disimpan pendaftar, jadi formatnya
 * snake_case, unik dalam satu periode, dan tidak boleh diganti setelah ada
 * yang menjawab.
 */
class PpdbFormFieldRequest extends FormRequest
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
        $field = $this->route('form_field');
        $periodId = $this->periodId();
        $isPost = $this->isMethod('post');

        return [
            'period_id' => [$isPost ? 'required' : 'sometimes', 'integer', 'exists:ppdb_periods,id'],
            'field_key' => [
                $isPost ? 'required' : 'sometimes',
                'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                PpdbPeriodService::uniqueInPeriod('ppdb_form_fields', 'field_key', $periodId, $field?->id),
            ],
            'label' => [$isPost ? 'required' : 'sometimes', 'string', 'max:150'],
            'type' => [$isPost ? 'required' : 'sometimes', 'string', Rule::in(PpdbFormField::TYPES)],
            'section' => [$isPost ? 'required' : 'sometimes', 'string', 'max:50', Rule::in(PpdbFormField::SECTIONS)],
            'placeholder' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'required' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0', 'max:65535'],

            // Opsi diterima sebagai `["A","B"]` maupun `[{label,value}]`.
            'options' => ['nullable', 'array'],
            'options.*' => ['filled'],
            'options.*.label' => ['nullable', 'string', 'max:150'],
            'options.*.value' => ['nullable', 'string', 'max:100'],

            // Potongan aturan Laravel yang ditambahkan ke jawaban field ini.
            'validation' => ['nullable', 'array'],
            'validation.*' => ['string', 'max:100'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $field = $this->route('form_field');

                if (! $field instanceof PpdbFormField || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $key = (string) $this->input('field_key');

                if ($key !== '' && $key !== $field->field_key && app(PpdbPeriodService::class)->fieldKeyInUse($field)) {
                    $validator->errors()->add(
                        'field_key',
                        'Kunci field sudah dipakai jawaban pendaftar dan tidak dapat diubah. '
                        .'Nonaktifkan field lama lalu buat field baru.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_id' => 'periode',
            'field_key' => 'kunci field',
            'options' => 'pilihan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_id.required' => 'Periode wajib dipilih.',
            'period_id.exists' => 'Periode tidak ditemukan.',
            'field_key.required' => 'Kunci field wajib diisi.',
            'field_key.regex' => 'Kunci field harus snake_case: huruf kecil, angka, atau garis bawah, diawali huruf.',
            'field_key.unique' => 'Kunci field sudah dipakai pada periode ini.',
            'label.required' => 'Label field wajib diisi.',
            'type.in' => 'Jenis field tidak dikenali.',
            'section.in' => 'Section tidak dikenali.',
            'options.array' => 'Pilihan harus berupa daftar.',
            'validation.array' => 'Aturan validasi harus berupa daftar teks.',
            'validation.*.string' => 'Tiap aturan validasi harus berupa teks, mis. "max:100".',
        ];
    }

    /** Periode efektif baris ini. */
    private function periodId(): ?int
    {
        if (filled($this->input('period_id'))) {
            return (int) $this->input('period_id');
        }

        $field = $this->route('form_field');

        return $field instanceof PpdbFormField ? (int) $field->period_id : null;
    }
}
