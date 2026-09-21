<?php

namespace App\Http\Requests\Ppdb;

use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Persyaratan dokumen pendaftaran.
 *
 * Batasan berkas disimpan sebagai data: `file_types` daftar ekstensi dipisah
 * koma, `min_size_kb`/`max_size_kb` dalam kilobyte.
 */
class PpdbDocumentRequirementRequest extends FormRequest
{
    /** Ekstensi: 2–5 karakter alfanumerik, tanpa titik. */
    private const EXTENSION_PATTERN = '/^[a-z0-9]{2,5}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * CSV dinormalkan lebih dulu (huruf kecil, tanpa spasi, tanpa duplikat)
     * sehingga nilai tersimpan selalu seragam.
     */
    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('file_types'))) {
            return;
        }

        $extensions = collect(explode(',', strtolower($this->input('file_types'))))
            ->map(fn (string $ext) => trim($ext, " \t\n\r\0\x0B."))
            ->filter()
            ->unique()
            ->values()
            ->implode(',');

        $this->merge(['file_types' => $extensions]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requirement = $this->route('document_requirement');
        $isPost = $this->isMethod('post');

        return [
            'period_id' => [$isPost ? 'required' : 'sometimes', 'integer', 'exists:ppdb_periods,id'],
            'program_id' => ['nullable', 'integer', 'exists:ppdb_programs,id'],
            'wave_id' => ['nullable', 'integer', 'exists:ppdb_waves,id'],
            'code' => [
                $isPost ? 'required' : 'sometimes',
                'string', 'max:50',
                PpdbPeriodService::uniqueInPeriod(
                    'ppdb_document_requirements',
                    'code',
                    $this->periodId(),
                    $requirement?->id,
                ),
            ],
            'name' => [$isPost ? 'required' : 'sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'required' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'file_types' => ['nullable', 'string', 'max:100'],
            'max_size_kb' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'min_size_kb' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->assertSinglePeriod($validator);
                $this->assertExtensions($validator);
                $this->assertSizeRange($validator);
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
            'program_id' => 'program',
            'wave_id' => 'gelombang',
            'file_types' => 'tipe berkas',
            'max_size_kb' => 'ukuran maksimal',
            'min_size_kb' => 'ukuran minimal',
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
            'program_id.exists' => 'Program PPDB terpilih tidak ada.',
            'wave_id.exists' => 'Gelombang terpilih tidak ada.',
            'code.required' => 'Kode persyaratan dokumen wajib diisi.',
            'code.unique' => 'Kode dokumen sudah dipakai pada periode ini.',
            'name.required' => 'Nama dokumen wajib diisi.',
            'max_size_kb.min' => 'Ukuran maksimal berkas harus lebih dari 0 KB.',
        ];
    }

    /** Setiap ekstensi pada `file_types` harus 2–5 karakter alfanumerik. */
    private function assertExtensions(Validator $validator): void
    {
        $types = $this->input('file_types');

        if (! is_string($types) || $types === '' || $validator->errors()->isNotEmpty()) {
            return;
        }

        $bad = array_values(array_filter(
            explode(',', $types),
            fn (string $ext) => preg_match(self::EXTENSION_PATTERN, $ext) !== 1,
        ));

        if ($bad !== []) {
            $validator->errors()->add(
                'file_types',
                'Ekstensi tidak valid: '.implode(', ', $bad)
                    .'. Gunakan 2–5 huruf/angka tanpa titik, mis. pdf, jpg, png.'
            );
        }
    }

    /** Minimal tidak boleh melebihi maksimal (nilai lama dipakai bila tidak dikirim). */
    private function assertSizeRange(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $requirement = $this->route('document_requirement');
        $max = $this->has('max_size_kb') ? $this->input('max_size_kb') : $requirement?->max_size_kb;
        $min = $this->has('min_size_kb') ? $this->input('min_size_kb') : $requirement?->min_size_kb;

        if (filled($max) && filled($min) && (int) $min > (int) $max) {
            $validator->errors()->add('min_size_kb', 'Ukuran minimal berkas tidak boleh melebihi ukuran maksimal.');
        }
    }

    /** Program/gelombang harus seperiode dengan dokumen ini. */
    private function assertSinglePeriod(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach (app(PpdbPeriodService::class)->scopeErrors(
            $this->periodId(),
            filled($this->input('program_id')) ? (int) $this->input('program_id') : null,
            filled($this->input('wave_id')) ? (int) $this->input('wave_id') : null,
        ) as $attribute => $message) {
            $validator->errors()->add($attribute, $message);
        }
    }

    /** Periode efektif baris ini. */
    private function periodId(): ?int
    {
        if (filled($this->input('period_id'))) {
            return (int) $this->input('period_id');
        }

        $requirement = $this->route('document_requirement');

        return $requirement?->period_id === null ? null : (int) $requirement->period_id;
    }
}
